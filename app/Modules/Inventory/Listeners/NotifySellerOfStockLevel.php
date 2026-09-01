<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Events\InventoryDepleted;
use App\Modules\Inventory\Events\InventoryLow;
use App\Modules\Inventory\Events\InventoryRestored;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Notifications\StockLevelChanged;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Support\Facades\DB;

/**
 * Mails the seller when a listing crosses a stock threshold.
 *
 * The events already fire only on a crossing, but that alone is not enough
 * to stop the spam §11 warns about: stock crosses the same line repeatedly
 * as holds are taken and released, so a customer adding and abandoning a
 * cart would send a "running low" mail each time.
 *
 * So the balance remembers the last state the seller was actually told
 * about, and this refuses to send the same one twice. That row is the
 * durable guard — it survives a queue retry, a redelivered event and a
 * deploy, none of which an in-memory check would.
 *
 * Restoration clears the marker rather than sending anything: a seller who
 * just restocked knows they restocked.
 */
final class NotifySellerOfStockLevel
{
    public function handle(InventoryLow|InventoryDepleted|InventoryRestored $event): void
    {
        $state = match (true) {
            $event instanceof InventoryDepleted => StockState::OutOfStock,
            $event instanceof InventoryLow => StockState::LowStock,
            default => StockState::InStock,
        };

        DB::transaction(function () use ($event, $state): void {
            /** @var InventoryBalance|null $balance */
            $balance = InventoryBalance::query()
                ->where('offer_id', $event->offerId)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                return;
            }

            $lastTold = $balance->notified_state === null
                ? StockState::InStock
                : StockState::from($balance->notified_state);

            /*
             * Only when it got worse than the last thing we said.
             *
             * A hold taken and abandoned walks an offer between low and
             * empty repeatedly; without this the seller gets a mail on
             * every lap. Recording the improvement without sending is what
             * re-arms the warning for the next genuine decline.
             */
            $worthSending = $state->warrantsNotification() && $state->isWorseThan($lastTold);

            $balance->forceFill([
                'notified_state' => $state->value,
                'notified_at' => $worthSending ? now() : $balance->notified_at,
            ])->save();

            if (! $worthSending) {
                return;
            }

            $this->mail($event->sellerAccountId, $balance, $state, $event->available);
        });
    }

    /**
     * Sent to the people who can actually do something about it.
     *
     * Membership rather than an address on the account, matching how every
     * other seller notification is addressed — and role-filtered, because
     * a finance manager cannot restock and does not need the mail.
     */
    private function mail(int $sellerAccountId, InventoryBalance $balance, StockState $state, int $available): void
    {
        $seller = SellerAccount::query()->find($sellerAccountId);
        $offer = $balance->offer;

        if ($seller === null || $offer === null) {
            return;
        }

        $notification = new StockLevelChanged(
            productTitle: $offer->product->title ?? 'A listing',
            sku: $offer->seller_sku,
            state: $state,
            available: $available,
        );

        $recipients = $seller->memberships()
            ->whereNotNull('accepted_at')
            ->with('user')
            ->get()
            ->filter(fn (SellerMembership $membership): bool => $membership->role->can(SellerPermission::InventoryManage))
            ->all();

        // After commit, so a rollback cannot leave a seller told about a
        // stock level that never existed.
        DB::afterCommit(function () use ($recipients, $notification): void {
            foreach ($recipients as $membership) {
                $membership->user?->notify(clone $notification);
            }
        });
    }
}
