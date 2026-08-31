<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payments\Actions\RecordWebhookEvent;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\WebhookEvent;
use App\Modules\Payments\Gateways\FakePaymentGateway;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Invariant 5 — a reprocessed payment event cannot create duplicate
 * financial entries.
 *
 * Providers retry. The guarantee is the unique index on
 * (provider, event_id): the second insert loses, the caller is told the
 * event was already handled, and no second ledger row is written. An
 * application-level "have I seen this?" check races under concurrency; a
 * unique index does not.
 */
final class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $id = 'evt_001'): WebhookEvent
    {
        return new WebhookEvent(
            provider: 'fake',
            eventId: $id,
            type: 'payment.captured',
            payload: ['id' => $id, 'type' => 'payment.captured', 'amount_minor' => 32_800],
        );
    }

    #[Test]
    public function the_same_event_is_recorded_once(): void
    {
        $first = app(RecordWebhookEvent::class)($this->event());
        $second = app(RecordWebhookEvent::class)($this->event());
        $third = app(RecordWebhookEvent::class)($this->event());

        $this->assertNotNull($first, 'The first delivery is recorded.');
        $this->assertNull($second, 'A replay reports "already handled" rather than inserting.');
        $this->assertNull($third);
        $this->assertSame(1, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function a_replayed_event_does_not_post_a_second_ledger_entry(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        // The handler shape: only act when the event is new.
        $handle = function (WebhookEvent $event) use ($seller): bool {
            if (app(RecordWebhookEvent::class)($event) === null) {
                return false;
            }

            app(PostLedgerEntry::class)(
                seller: $seller,
                type: LedgerEntryType::SaleEarning,
                amountMinor: 28_864,
                note: "From {$event->eventId}",
            );

            return true;
        };

        $this->assertTrue($handle($this->event()));
        $this->assertFalse($handle($this->event()), 'The retry is a no-op.');
        $this->assertFalse($handle($this->event()));

        $entries = SellerLedgerEntry::query()->withoutGlobalScopes()->where('seller_account_id', $seller->id)->get();

        $this->assertCount(1, $entries, 'Three deliveries, one earning.');
        $this->assertSame(28_864, (int) $entries->sum('amount_minor'));
    }

    #[Test]
    public function distinct_events_are_each_recorded(): void
    {
        $this->assertNotNull(app(RecordWebhookEvent::class)($this->event('evt_a')));
        $this->assertNotNull(app(RecordWebhookEvent::class)($this->event('evt_b')));

        $this->assertSame(2, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function the_same_event_id_from_a_different_provider_is_not_a_duplicate(): void
    {
        app(RecordWebhookEvent::class)($this->event('evt_shared'));

        $other = new WebhookEvent('other', 'evt_shared', 'payment.captured', ['id' => 'evt_shared']);

        $this->assertNotNull(app(RecordWebhookEvent::class)($other));
        $this->assertSame(2, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function an_unsigned_webhook_is_rejected(): void
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $payload = json_encode(['id' => 'evt_x', 'type' => 'payment.captured']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature verification failed');

        $gateway->parseWebhook($payload, 'not-the-signature');
    }

    #[Test]
    public function a_correctly_signed_webhook_is_parsed(): void
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $payload = json_encode(['id' => 'evt_ok', 'type' => 'payment.captured']);

        $event = $gateway->parseWebhook($payload, $gateway->sign($payload));

        $this->assertSame('evt_ok', $event->eventId);
        $this->assertSame('payment.captured', $event->type);
    }

    #[Test]
    public function the_capture_path_is_idempotent_by_key(): void
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $a = $gateway->capture('VC-24081', 32_800, 'USD', 'VC-24081:capture');
        $b = $gateway->capture('VC-24081', 32_800, 'USD', 'VC-24081:capture');

        $this->assertTrue($a->succeeded);
        $this->assertSame($a->chargeId, $b->chargeId, 'The same key yields the same charge, never a second one.');
    }
}
