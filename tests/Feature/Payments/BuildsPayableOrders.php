<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Actions\FinalizePayment;
use App\Modules\Payments\Actions\FinalizeRefund;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Actions\RecordPaymentFailure;
use App\Modules\Payments\Adapters\FakePaymentProvider;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Jobs\ProcessProviderEvent;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\ProviderWebhookEvent;

/**
 * A payment-pending order, prepared for payment, and the provider events
 * that follow — all through the real actions.
 *
 * Tests that inserted payment rows directly could satisfy every assertion
 * while the real path produced something different, so nothing here takes
 * a shortcut past PreparePayment or the webhook pipeline.
 */
trait BuildsPayableOrders
{
    protected function provider(): FakePaymentProvider
    {
        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProvider::class);

        return $provider;
    }

    /** @return array{attempt: PaymentAttempt, reference: string} */
    protected function prepare(MarketplaceOrder $order): array
    {
        ['attempt' => $attempt] = app(PreparePayment::class)($order);

        return ['attempt' => $attempt, 'reference' => (string) $attempt->provider_reference];
    }

    /**
     * Deliver a signed provider event the way Stripe would, through the
     * real HTTP endpoint, and process it.
     */
    protected function deliverEvent(string $type, string $paymentReference, ?string $eventId = null, ?int $occurredAt = null): ProviderWebhookEvent
    {
        $provider = $this->provider();

        $signed = $provider->signedEvent(
            $type,
            $provider->paymentObject($paymentReference),
            $eventId,
            $occurredAt,
        );

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        );

        /** @var ProviderWebhookEvent $event */
        $event = ProviderWebhookEvent::query()->where('event_id', $signed['event']['id'])->firstOrFail();

        return $event;
    }

    /**
     * The provider's own word about a refund, delivered as a signed event.
     *
     * §43: the request that asked for a refund is not the final authority,
     * so the refund path has to be exercisable the same way the payment
     * path is — through the endpoint, with a signature.
     */
    protected function deliverRefundEvent(string $type, string $refundReference, ?string $eventId = null): ProviderWebhookEvent
    {
        $provider = $this->provider();

        $signed = $provider->signedEvent($type, $provider->refundObject($refundReference), $eventId);

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        );

        /** @var ProviderWebhookEvent $event */
        $event = ProviderWebhookEvent::query()->where('event_id', $signed['event']['id'])->firstOrFail();

        return $event;
    }

    /** The whole happy path: prepare, settle at the provider, deliver success. */
    protected function payFor(MarketplaceOrder $order, ?int $capturedMinor = null): string
    {
        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded, $capturedMinor);
        $this->deliverEvent('payment_intent.succeeded', $reference);

        return $reference;
    }

    /** Run a stored event's processing job again, as a retry would. */
    protected function reprocess(ProviderWebhookEvent $event): void
    {
        app(ProcessProviderEvent::class, ['providerEventId' => $event->id])->handle(
            app(FinalizePayment::class),
            app(RecordPaymentFailure::class),
            app(FinalizeRefund::class),
        );
    }
}
