<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Payments\Adapters\StripePaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\ProviderSignatureInvalid;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The translation layer, tested without touching the network.
 *
 * Everything here is what the adapter does with an answer, not how it gets
 * one: the status vocabulary, the signature check, the shape of the DTO
 * that leaves. Those are the parts a Stripe outage cannot excuse being
 * wrong about, and the parts that decide whether an order gets marked paid
 * on a status that meant the opposite.
 *
 * The real network round trip is a separate gate (§68) and is reported
 * honestly as unverified when no credentials are available. This is not a
 * substitute for it and does not claim to be.
 */
final class StripeAdapterTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_for_signing';

    private function adapter(): StripePaymentProvider
    {
        // No request is made by anything under test here, so an offline
        // client with a placeholder key is honest rather than a stub.
        return new StripePaymentProvider(
            new StripeClient(['api_key' => 'sk_test_offline']),
            self::SECRET,
        );
    }

    /** A real Stripe-Signature header, built the way Stripe builds one. */
    private function sign(string $payload, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    private function eventPayload(string $id = 'evt_1', string $type = 'payment_intent.succeeded'): string
    {
        return (string) json_encode([
            'id' => $id,
            'type' => $type,
            'created' => time(),
            'data' => ['object' => ['id' => 'pi_abc123', 'object' => 'payment_intent', 'amount' => 4_000]],
        ], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function stripe_statuses_translate_to_the_platforms_own_vocabulary(): void
    {
        $translate = new ReflectionMethod(StripePaymentProvider::class, 'translateIntentStatus');
        $adapter = $this->adapter();

        $expected = [
            'requires_payment_method' => PaymentAttemptStatus::RequiresPaymentMethod,
            'requires_confirmation' => PaymentAttemptStatus::RequiresAction,
            'requires_action' => PaymentAttemptStatus::RequiresAction,
            'processing' => PaymentAttemptStatus::Processing,
            'succeeded' => PaymentAttemptStatus::Succeeded,
            'canceled' => PaymentAttemptStatus::Cancelled,
        ];

        foreach ($expected as $stripe => $ours) {
            $this->assertSame($ours, $translate->invoke($adapter, $stripe), "Stripe's `{$stripe}`.");
        }

        /*
         * An authorised-but-uncaptured payment is money the platform has
         * not received. Calling it Succeeded is how goods ship against an
         * authorisation that later expires.
         */
        $this->assertSame(PaymentAttemptStatus::Processing, $translate->invoke($adapter, 'requires_capture'));

        // And a status nobody has seen before is not a licence to guess a
        // terminal state.
        $this->assertSame(PaymentAttemptStatus::Processing, $translate->invoke($adapter, 'some_future_status'));
    }

    #[Test]
    public function refund_statuses_translate_and_an_unknown_one_is_not_a_success(): void
    {
        $translate = new ReflectionMethod(StripePaymentProvider::class, 'translateRefundStatus');
        $adapter = $this->adapter();

        $this->assertSame(RefundStatus::Succeeded, $translate->invoke($adapter, 'succeeded'));
        $this->assertSame(RefundStatus::Failed, $translate->invoke($adapter, 'failed'));
        $this->assertSame(RefundStatus::Failed, $translate->invoke($adapter, 'canceled'));
        $this->assertSame(RefundStatus::Processing, $translate->invoke($adapter, 'pending'));
        $this->assertSame(RefundStatus::Processing, $translate->invoke($adapter, 'anything_else'));
    }

    #[Test]
    public function no_stripe_status_string_reaches_the_platforms_own_enum(): void
    {
        $ours = array_map(
            static fn (PaymentAttemptStatus $status): string => $status->value,
            PaymentAttemptStatus::cases(),
        );

        // Stripe's vocabulary and the platform's overlap by coincidence in
        // three places and diverge everywhere else. These are the ones that
        // must never appear as an internal state.
        foreach (['requires_capture', 'canceled', 'requires_confirmation'] as $stripeOnly) {
            $this->assertNotContains($stripeOnly, $ours, "`{$stripeOnly}` is Stripe's word, not ours.");
        }

        // The platform spells it with two Ls, which is the tell that the
        // value is its own and not the provider's echoed back.
        $this->assertContains('cancelled', $ours);
    }

    #[Test]
    public function a_correctly_signed_stripe_event_is_parsed(): void
    {
        $payload = $this->eventPayload('evt_signed');

        $event = $this->adapter()->parseEvent($payload, $this->sign($payload));

        $this->assertSame('stripe', $event->provider);
        $this->assertSame('evt_signed', $event->eventId);
        $this->assertSame('payment_intent.succeeded', $event->type);
        $this->assertSame('pi_abc123', $event->objectReference);
        $this->assertSame(4_000, $event->payload['amount'] ?? null);
    }

    #[Test]
    public function a_signature_made_with_another_secret_is_refused(): void
    {
        $payload = $this->eventPayload();

        $this->expectException(ProviderSignatureInvalid::class);

        $this->adapter()->parseEvent($payload, $this->sign($payload, secret: 'whsec_someone_elses_secret'));
    }

    #[Test]
    public function a_body_altered_after_signing_is_refused(): void
    {
        $payload = $this->eventPayload();
        $signature = $this->sign($payload);

        // One digit changed — the amount an attacker would most like to
        // change — and the HMAC no longer matches.
        $tampered = str_replace('"amount":4000', '"amount":1', $payload);

        $this->assertNotSame($payload, $tampered);

        $this->expectException(ProviderSignatureInvalid::class);

        $this->adapter()->parseEvent($tampered, $signature);
    }

    #[Test]
    public function a_signature_replayed_long_after_its_timestamp_is_refused(): void
    {
        $payload = $this->eventPayload();

        // Correctly signed, and hours old. Stripe's tolerance window is
        // the replay guard, and it is deliberately not reimplemented here.
        $this->expectException(ProviderSignatureInvalid::class);

        $this->adapter()->parseEvent($payload, $this->sign($payload, timestamp: time() - 86_400));
    }

    #[Test]
    public function an_unparseable_body_is_refused_rather_than_guessed_at(): void
    {
        $this->expectException(ProviderSignatureInvalid::class);

        $this->adapter()->parseEvent('not json at all', $this->sign('not json at all'));
    }

    #[Test]
    public function the_adapter_names_itself_so_events_can_be_scoped_to_it(): void
    {
        // The (provider, event_id) unique index depends on this: two
        // providers may legitimately issue the same event id.
        $this->assertSame('stripe', $this->adapter()->name());
        $this->assertSame(StripePaymentProvider::NAME, $this->adapter()->name());
    }
}
