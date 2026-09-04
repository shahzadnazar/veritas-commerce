<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Orders\Actions\AllocateReference;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentRefused;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Models\RefundAllocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Requests a refund, allocated to the items whose money it returns.
 *
 * §38 is the shape of this: "refund $50" is not a financial instruction
 * until it says whose $50. A marketplace order is three sellers' money in
 * one payment, and refunding the customer without knowing which seller's
 * obligation it reverses leaves the platform unable to answer what anyone
 * is owed. So a refund is a set of allocations against order items, and the
 * amount is their sum.
 *
 * §39 is the arithmetic: each allocation's commission and earning reversal
 * are taken from the ORDER ITEM'S OWN SNAPSHOT, proportionally to the
 * amount being returned. An order taken at 12% that is refunded after the
 * platform moved to 15% reverses twelve percent, because that is what was
 * charged. There is no path from here to a commission rule.
 *
 * §37's guard is a locked read of the payment plus the sum of every refund
 * still holding balance, so two admins refunding at once cannot between
 * them return more than was captured.
 *
 * Nothing financial is reversed here. The request goes to the provider and
 * the reversals wait for the provider to say the money left (§44).
 */
final class RequestRefund
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly AllocateReference $references,
        private readonly FinalizeRefund $finalize,
    ) {}

    /**
     * @param  array<int, array{order_item_id: int, amount_minor: int, quantity?: int}>  $lines
     *
     * @throws PaymentRefused
     */
    public function __invoke(
        MarketplaceOrder $order,
        array $lines,
        string $reason,
        ?int $adminId = null,
        ?string $idempotencyKey = null,
    ): Refund {
        if (trim($reason) === '') {
            // §36: a refund without a reason is an unexplained withdrawal
            // from the platform's own account.
            throw new PaymentRefused('A refund needs a reason.', 'reason_required');
        }

        if ($lines === []) {
            throw new PaymentRefused('A refund needs at least one item.', 'no_allocations');
        }

        if ($idempotencyKey !== null) {
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                // A double-clicked refund button is one refund.
                return $existing;
            }
        }

        $refund = DB::transaction(function () use ($order, $lines, $reason, $adminId, $idempotencyKey): Refund {
            /** @var Payment $payment */
            $payment = Payment::query()
                ->where('marketplace_order_id', $order->id)
                ->lockForUpdate()
                ->firstOr(fn () => throw new PaymentRefused(
                    'This order has no captured payment to refund.',
                    'not_captured',
                ));

            $allocations = $this->allocate($order, $lines);
            $amount = array_sum(array_column($allocations, 'amount_minor'));

            if ($amount <= 0) {
                throw new PaymentRefused('A refund must be for more than nothing.', 'zero_amount');
            }

            /*
             * The refundable balance, read under the payment's lock.
             *
             * Every refund that has not failed counts — a requested one has
             * not moved money yet but has claimed the right to, and letting
             * a second request ignore it is how two admins between them
             * refund 140% of a payment.
             */
            $claimed = (int) Refund::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', $this->balanceHoldingStatuses())
                ->sum('amount_minor');

            $remaining = $payment->amount_minor - $claimed;

            if ($amount > $remaining) {
                throw new PaymentRefused(
                    "Only {$remaining} minor units remain refundable on this payment.",
                    'exceeds_refundable',
                );
            }

            $refund = Refund::query()->create([
                'reference' => $this->references->refundReference(),
                'marketplace_order_id' => $order->id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'idempotency_key' => $idempotencyKey,
                'currency' => $payment->currency,
                'amount_minor' => $amount,
                'status' => RefundStatus::Requested->value,
                'reason' => $reason,
                'requested_by_admin_id' => $adminId,
                'requested_at' => now(),
            ]);

            foreach ($allocations as $allocation) {
                RefundAllocation::query()->create([
                    'refund_id' => $refund->id,
                    'seller_order_id' => $allocation['seller_order_id'],
                    'order_item_id' => $allocation['order_item_id'],
                    'currency' => $allocation['currency'],
                    'quantity' => $allocation['quantity'],
                    'amount_minor' => $allocation['amount_minor'],
                    'commission_reversed_minor' => $allocation['commission_reversed_minor'],
                    'earning_reversed_minor' => $allocation['earning_reversed_minor'],
                    'created_at' => now(),
                ]);
            }

            return $refund;
        });

        /*
         * The provider call happens outside the transaction.
         *
         * A network call inside a transaction holding a payment row locked
         * is how a provider's slow day becomes a database that cannot serve
         * anybody else's refund.
         */
        $providerRefund = $this->provider->refundPayment(
            $this->providerReferenceFor($refund),
            $refund->amount_minor,
            'refund:'.$refund->public_id,
            $reason,
        );

        $refund->forceFill(['provider_refund_reference' => $providerRefund->reference])->save();

        // Providers that settle immediately are finalized now; the rest
        // wait for the event. Both paths run the same code.
        ($this->finalize)($providerRefund->reference, null, $providerRefund);

        return $refund->refresh();
    }

    /**
     * Turn requested lines into allocations, from the items' own snapshots.
     *
     * The reversal split is proportional and computed the same way the
     * original split was — the platform's share rounded, the seller's the
     * remainder — so a full refund of a line reverses exactly what that
     * line recorded, and the database CHECK on the allocation holds.
     *
     * @param  array<int, array{order_item_id: int, amount_minor: int, quantity?: int}>  $lines
     * @return array<int, array{seller_order_id: int, order_item_id: int, currency: string, quantity: int, amount_minor: int, commission_reversed_minor: int, earning_reversed_minor: int}>
     */
    private function allocate(MarketplaceOrder $order, array $lines): array
    {
        $sellerOrderIds = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->pluck('id');

        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()
            ->whereIn('seller_order_id', $sellerOrderIds)
            ->whereIn('id', array_column($lines, 'order_item_id'))
            ->get()
            ->keyBy('id');

        $allocations = [];

        foreach ($lines as $line) {
            $item = $items->get($line['order_item_id']);

            if ($item === null) {
                // An item from another order, or one that does not exist.
                throw new PaymentRefused(
                    'One of the items is not part of this order.',
                    'item_not_in_order',
                );
            }

            $amount = (int) $line['amount_minor'];

            $alreadyReturned = (int) RefundAllocation::query()
                ->where('order_item_id', $item->id)
                ->whereIn('refund_id', Refund::query()
                    ->whereIn('status', $this->balanceHoldingStatuses())
                    ->select('id'))
                ->sum('amount_minor');

            if ($amount <= 0 || $amount + $alreadyReturned > $item->line_total_minor) {
                throw new PaymentRefused(
                    'That is more than remains refundable on one of the items.',
                    'exceeds_item_refundable',
                );
            }

            [$commission, $earning] = $this->splitFromSnapshot($item, $amount);

            $allocations[] = [
                'seller_order_id' => (int) $item->seller_order_id,
                'order_item_id' => (int) $item->id,
                'currency' => (string) $item->currency,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'amount_minor' => $amount,
                'commission_reversed_minor' => $commission,
                'earning_reversed_minor' => $earning,
            ];
        }

        return $allocations;
    }

    /**
     * The reversal split, from the item's recorded figures.
     *
     * A full refund of a line reverses exactly the commission and earning
     * that line recorded — no rounding drift, because the full case is
     * taken from the snapshot directly rather than recomputed from a rate.
     *
     * @return array{int, int}
     */
    private function splitFromSnapshot(OrderItem $item, int $amount): array
    {
        if ($amount === $item->line_total_minor) {
            return [$item->commission_amount_minor, $item->seller_earning_amount_minor];
        }

        // Partial: the platform's share in proportion, rounded half up,
        // and the seller gets the remainder — the same rule the original
        // split used, so the two can never disagree by more than the
        // rounding the original itself accepted.
        $scaled = $item->commission_amount_minor * $amount;
        $commission = intdiv($scaled, $item->line_total_minor);

        if (($scaled % $item->line_total_minor) * 2 >= $item->line_total_minor) {
            $commission++;
        }

        return [$commission, $amount - $commission];
    }

    /** @return array<int, string> */
    private function balanceHoldingStatuses(): array
    {
        return array_values(array_map(
            static fn (RefundStatus $s): string => $s->value,
            array_filter(RefundStatus::cases(), static fn (RefundStatus $s): bool => $s->holdsBalance()),
        ));
    }

    private function providerReferenceFor(Refund $refund): string
    {
        $attempt = DB::table('payment_attempts')
            ->where('marketplace_order_id', $refund->marketplace_order_id)
            ->where('status', 'succeeded')
            ->value('provider_reference');

        return is_string($attempt) ? $attempt : '';
    }
}
