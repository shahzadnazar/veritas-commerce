<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentRefused;
use App\Modules\Payments\Exceptions\ProviderUnavailable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Models\RefundAllocation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the platform captured, what it returned, and why.
 *
 * The read side answers the two questions an operator actually has: did
 * this order pay, and where did the money go. The write side is one
 * action — issue a refund — and it is the only place in the application a
 * person can move money back out, which is why it is behind its own
 * permission and why the reason is required by this controller rather
 * than by a form (§36: a refund without a reason is an unexplained
 * withdrawal from the platform's own account).
 *
 * Nothing here renders card data, because none is stored. Nothing renders
 * a client secret or a provider payload by default either: the raw event
 * bodies sit behind `payments.events.view`, held by the roles that
 * reconcile money rather than everyone who can look an order up.
 */
final class AdminPaymentController
{
    public function __construct(
        private readonly RequestRefund $refunds,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Payment::query()->orderByDesc('id');

        if (($filters['status'] ?? null) !== null && PaymentStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->whereDate('captured_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->whereDate('captured_at', '<=', $filters['to']);
        }

        if (($filters['reference'] ?? null) !== null) {
            $query->whereIn('marketplace_order_id', MarketplaceOrder::query()
                ->where('reference', 'ilike', '%'.$filters['reference'].'%')
                ->select('id'));
        }

        /** @var LengthAwarePaginator<int, Payment> $payments */
        $payments = $query->paginate(25)->withQueryString();

        $references = MarketplaceOrder::query()
            ->whereIn('id', array_map(static fn (Payment $p): int => $p->marketplace_order_id, $payments->items()))
            ->pluck('reference', 'id');

        return Inertia::render('Payments/Index', [
            'payments' => [
                'data' => array_map(
                    static fn (Payment $payment): array => [
                        'publicId' => $payment->public_id,
                        'orderReference' => (string) ($references[$payment->marketplace_order_id] ?? '—'),
                        'provider' => $payment->provider,
                        'status' => $payment->status->value,
                        'capturedAt' => $payment->captured_at?->toIso8601String(),
                        'amount' => Money::of($payment->amount_minor, $payment->currency)->format(),
                        'amountMinor' => $payment->amount_minor,
                        'refunded' => Money::of($payment->refunded_amount_minor, $payment->currency)->format(),
                        'refundedMinor' => $payment->refunded_amount_minor,
                        'netMinor' => $payment->amount_minor - $payment->refunded_amount_minor,
                    ],
                    $payments->items(),
                ),
                'currentPage' => $payments->currentPage(),
                'lastPage' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
            'filters' => $filters,
            'statuses' => array_map(
                static fn (PaymentStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                PaymentStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $order = $this->orderOrFail($reference);
        $admin = $request->user('admin');

        $mayIssueRefunds = $admin !== null && $admin->role->can(AdminPermission::IssueRefunds);
        $maySeeEvents = $admin !== null && $admin->role->can(AdminPermission::ViewProviderEvents);

        /** @var Payment|null $payment */
        $payment = Payment::query()->where('marketplace_order_id', $order->id)->first();

        return Inertia::render('Payments/Show', [
            'order' => [
                'reference' => $order->reference,
                'status' => $order->status->value,
                'placedAt' => $order->placed_at?->toIso8601String(),
                'grandTotal' => Money::of($order->grand_total_minor, $order->currency)->format(),
            ],
            'payment' => $payment === null ? null : [
                'publicId' => $payment->public_id,
                'provider' => $payment->provider,
                'status' => $payment->status->value,
                'capturedAt' => $payment->captured_at?->toIso8601String(),
                'amount' => Money::of($payment->amount_minor, $payment->currency)->format(),
                'amountMinor' => $payment->amount_minor,
                'refundedMinor' => $payment->refunded_amount_minor,
                'refundableMinor' => $payment->amount_minor - $payment->refunded_amount_minor,
                'currency' => $payment->currency,
            ],
            'attempts' => $this->attempts($order),
            'transactions' => $this->transactions($order),
            'refunds' => $this->refundHistory($order),
            'refundableItems' => $mayIssueRefunds ? $this->refundableItems($order) : [],
            'providerEvents' => $maySeeEvents ? $this->providerEvents($order) : [],
            'can' => [
                'refund' => $mayIssueRefunds,
                'viewEvents' => $maySeeEvents,
            ],
        ]);
    }

    /**
     * Issue a refund against this order's payment.
     *
     * The reason is validated here and not only in the browser, and the
     * lines name the items whose money is coming back — §38: "refund $50"
     * is not a financial instruction until it says whose $50.
     */
    public function refund(Request $request, string $reference): RedirectResponse
    {
        $order = $this->orderOrFail($reference);
        $admin = $request->user('admin');

        $validated = $request->validate([
            // Long enough to be a sentence. A refund reason is read months
            // later by somebody reconciling an account.
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.amount_minor' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            // Sent by the form so a double submit is one refund.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $refund = ($this->refunds)(
                order: $order,
                lines: $validated['lines'],
                reason: $validated['reason'],
                adminId: $admin === null ? null : (int) $admin->getAuthIdentifier(),
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );
        } catch (PaymentRefused $refused) {
            return back()->withErrors(['refund' => $refused->getMessage()]);
        } catch (ProviderUnavailable) {
            return back()->withErrors([
                'refund' => 'The payment provider could not be reached. Nothing has been refunded — try again.',
            ]);
        }

        /*
         * Audited with the reason, because this is the one action that
         * takes money out of the platform's account. The amount and the
         * refund's own reference are enough to find everything else; no
         * provider identifiers go into the log.
         */
        ($this->audit)(
            action: 'payment.refunded',
            actorType: 'admin',
            actorId: $admin === null ? null : (int) $admin->getAuthIdentifier(),
            subjectType: Refund::class,
            subjectId: $refund->id,
            changes: [
                'order_reference' => $order->reference,
                'refund_reference' => $refund->reference,
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'status' => $refund->status->value,
            ],
            reason: $validated['reason'],
        );

        return back()->with('status', "Refund {$refund->reference} requested.");
    }

    private function orderOrFail(string $reference): MarketplaceOrder
    {
        /** @var MarketplaceOrder $order */
        $order = MarketplaceOrder::query()->where('reference', $reference)->firstOrFail();

        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    private function attempts(MarketplaceOrder $order): array
    {
        return PaymentAttempt::query()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (PaymentAttempt $attempt): array => [
                'publicId' => $attempt->public_id,
                'status' => $attempt->status->value,
                'provider' => $attempt->provider,
                'providerStatus' => $attempt->provider_status,
                'reference' => $attempt->provider_reference,
                'method' => $attempt->method,
                // The provider's own words, for the operator. §53 keeps
                // these out of everything the customer ever sees.
                'failureCode' => $attempt->failure_code,
                'failureMessage' => $attempt->failure_message,
                'createdAt' => $attempt->created_at->toIso8601String(),
                'succeededAt' => $attempt->succeeded_at?->toIso8601String(),
                'failedAt' => $attempt->failed_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function transactions(MarketplaceOrder $order): array
    {
        return PaymentTransaction::query()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (PaymentTransaction $transaction): array => [
                'publicId' => $transaction->public_id,
                'type' => $transaction->type->value,
                // Signed, so the column sums to the order's net position.
                'amountMinor' => $transaction->amount_minor,
                'amount' => Money::of(abs($transaction->amount_minor), $transaction->currency)->format(),
                'status' => $transaction->status,
                'occurredAt' => $transaction->occurred_at->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function refundHistory(MarketplaceOrder $order): array
    {
        $refunds = Refund::query()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('id')
            ->get();

        $allocations = RefundAllocation::query()
            ->whereIn('refund_id', $refunds->pluck('id'))
            ->get()
            ->groupBy('refund_id');

        return $refunds
            ->map(static fn (Refund $refund): array => [
                'reference' => $refund->reference,
                'status' => $refund->status->value,
                'amount' => Money::of($refund->amount_minor, $refund->currency)->format(),
                'amountMinor' => $refund->amount_minor,
                'reason' => $refund->reason,
                'requestedAt' => $refund->requested_at->toIso8601String(),
                'succeededAt' => $refund->succeeded_at?->toIso8601String(),
                'failedAt' => $refund->failed_at?->toIso8601String(),
                'allocations' => ($allocations[$refund->id] ?? collect())
                    ->map(static fn (RefundAllocation $allocation): array => [
                        'orderItemId' => $allocation->order_item_id,
                        'amountMinor' => $allocation->amount_minor,
                        'commissionReversedMinor' => $allocation->commission_reversed_minor,
                        'earningReversedMinor' => $allocation->earning_reversed_minor,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * What is left to refund, line by line.
     *
     * The remaining figure is the item's own snapshot minus what refunds
     * still holding balance have claimed against it, so a second refund
     * dialog cannot be opened on money already promised back.
     *
     * @return array<int, array<string, mixed>>
     */
    private function refundableItems(MarketplaceOrder $order): array
    {
        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->get()
            ->keyBy('id');

        $items = OrderItem::query()
            ->whereIn('seller_order_id', $sellerOrders->keys())
            ->orderBy('id')
            ->get();

        $claimed = RefundAllocation::query()
            ->whereIn('order_item_id', $items->pluck('id'))
            ->whereIn('refund_id', Refund::query()
                ->whereIn('status', ['requested', 'processing', 'succeeded'])
                ->select('id'))
            ->get()
            ->groupBy('order_item_id')
            ->map(static fn ($rows): int => (int) $rows->sum('amount_minor'));

        return $items
            ->map(static function (OrderItem $item) use ($sellerOrders, $claimed): array {
                $already = (int) ($claimed[$item->id] ?? 0);

                return [
                    'id' => $item->id,
                    'sellerOrderReference' => (string) ($sellerOrders[$item->seller_order_id]->reference ?? '—'),
                    'title' => $item->product_title,
                    'quantity' => $item->quantity,
                    'lineTotalMinor' => $item->line_total_minor,
                    'refundedMinor' => $already,
                    'refundableMinor' => max(0, $item->line_total_minor - $already),
                    'currency' => $item->currency,
                ];
            })
            ->all();
    }

    /**
     * The provider events behind this order, for an incident.
     *
     * Metadata only: which event, of what type, when, and what the
     * platform did with it. The stored payload is deliberately not
     * rendered — it is the one place a provider's own description of a
     * payment method sits, and a screen is not where that belongs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function providerEvents(MarketplaceOrder $order): array
    {
        $references = PaymentAttempt::query()
            ->where('marketplace_order_id', $order->id)
            ->whereNotNull('provider_reference')
            ->pluck('provider_reference');

        if ($references->isEmpty()) {
            return [];
        }

        return ProviderWebhookEvent::query()
            ->whereIn('object_reference', $references)
            ->orderBy('id')
            ->get()
            ->map(static fn (ProviderWebhookEvent $event): array => [
                'eventId' => $event->event_id,
                'type' => $event->type,
                'status' => $event->status->value,
                'attempts' => $event->attempts,
                'receivedAt' => $event->received_at->toIso8601String(),
                'processedAt' => $event->processed_at?->toIso8601String(),
                'failedAt' => $event->failed_at?->toIso8601String(),
            ])
            ->all();
    }
}
