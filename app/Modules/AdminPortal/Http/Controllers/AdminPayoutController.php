<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\CancelPayoutRequest;
use App\Modules\Payouts\Actions\FailPayoutSettlement;
use App\Modules\Payouts\Actions\PostFinancialAdjustment;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RejectPayout;
use App\Modules\Payouts\Actions\RetryPayoutSettlement;
use App\Modules\Payouts\Actions\StartPayoutReview;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\BuildPayoutView;
use App\Modules\Payouts\Queries\BuildSellerStatement;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Payout operations for the platform team. §21–§27.
 *
 * Every route is gated on its own permission in the route file — review,
 * approve, reject and settle are four different acts of trust and the
 * middleware says so. Nothing here decides anything financial; it finds
 * the request, checks the shape, and calls the domain action, which is
 * where the locks and the ledger writes live.
 *
 * The queue's finance context is read with one grouped query for the whole
 * page (GetSellerFinancialPosition::forSellers), not one per row.
 */
final class AdminPayoutController
{
    public function __construct(
        private readonly BuildPayoutView $view,
        private readonly GetSellerFinancialPosition $position,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** §21 — the queue, filtered and paged in SQL. */
    public function index(Request $request): Response
    {
        $currency = $request->string('currency')->toString() ?: PayoutPolicy::currency();

        $query = PayoutRequest::query()
            ->withoutGlobalScopes()
            ->where('currency', strtoupper($currency))
            ->orderByDesc('id');

        if (($status = $request->string('status')->toString()) !== '') {
            $status === 'open'
                ? $query->whereIn('status', PayoutStatus::openValues())
                : $query->where('status', $status);
        }

        if (($seller = $request->string('seller')->toString()) !== '') {
            $query->whereIn('seller_account_id', SellerAccount::query()
                ->where('legal_name', 'ilike', '%'.$seller.'%')
                ->orWhere('public_id', $seller)
                ->limit(50)
                ->pluck('id'));
        }

        if (($from = $request->date('from')) !== null) {
            $query->where('requested_at', '>=', $from);
        }

        if (($to = $request->date('to')) !== null) {
            $query->where('requested_at', '<=', $to);
        }

        $paginator = $query->paginate(25)->withQueryString();

        /** @var array<int, PayoutRequest> $items */
        $items = $paginator->items();

        $sellerIds = array_values(array_unique(array_map(
            static fn (PayoutRequest $payout): int => (int) $payout->seller_account_id,
            $items,
        )));

        $positions = ($this->position)->forSellers($sellerIds, strtoupper($currency));
        $names = SellerAccount::query()->whereIn('id', $sellerIds)->pluck('legal_name', 'id');

        $rows = array_map(function (PayoutRequest $payout) use ($positions, $names): array {
            $position = $positions[(int) $payout->seller_account_id] ?? null;

            return array_merge($this->view->summarise($payout), [
                'sellerName' => (string) ($names[$payout->seller_account_id] ?? $payout->seller_name_snapshot),
                // Context, so a reviewer does not have to open the record
                // to see whether the store can actually fund this.
                'sellerWithdrawable' => $position === null
                    ? null
                    : Money::formatSigned($position->withdrawableMinor(), $position->currency),
                'sellerIsNegative' => $position?->isNegative() ?? false,
            ]);
        }, $items);

        return Inertia::render('Payouts/Index', [
            'payouts' => $rows,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'status' => $request->string('status')->toString(),
                'seller' => $seller,
                'currency' => strtoupper($currency),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ],
            'statuses' => array_map(
                static fn (PayoutStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                PayoutStatus::cases(),
            ),
            'currencies' => PayoutPolicy::supportedCurrencies(),
            'can' => $this->capabilities($request),
        ]);
    }

    /** §22 — one payout, with the finance context around it. */
    public function show(Request $request, string $reference): Response
    {
        $payout = $this->findOrFail($reference);
        $position = ($this->position)((int) $payout->seller_account_id, $payout->currency);

        $seller = SellerAccount::query()->whereKey($payout->seller_account_id)->first();

        return Inertia::render('Payouts/Show', [
            'payout' => $this->view->detail(
                $payout,
                // Bank-identifying metadata only for the roles that hold
                // payouts.view_sensitive. Support sees the amount and the
                // date; they do not see the account.
                withSensitive: $this->may($request, AdminPermission::ViewPayoutsSensitive),
            ),
            'seller' => $seller === null ? null : [
                'id' => $seller->public_id,
                'name' => $seller->legal_name,
                'status' => $seller->status->value,
                'statusLabel' => $seller->status->label(),
            ],
            'position' => $position->toArray(),
            /*
             * What the store can still withdraw once this payout settles.
             *
             * For an OPEN payout that is simply the current withdrawable:
             * the amount is already held out by its allocations, so
             * settling it changes the figure by nothing. Asked of a payout
             * that has already been paid or ended, the question is moot —
             * and subtracting the amount again would double-count a debit
             * the position already carries — so it is answered with
             * nothing rather than with a number that looks like one.
             */
            'withdrawableAfter' => $payout->status->holdsBalance()
                ? Money::of($position->withdrawableMinor(), $payout->currency)->format()
                : null,
            'can' => $this->capabilities($request),
        ]);
    }

    public function review(Request $request, string $reference): RedirectResponse
    {
        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(StartPayoutReview::class)($payout, $this->actor($request)),
            'payouts.review.started',
            'Payout opened for review.',
        );
    }

    public function approve(Request $request, string $reference): RedirectResponse
    {
        $note = $request->string('note')->toString() ?: null;

        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(ApprovePayout::class)($payout, $this->actor($request), $note),
            'payouts.approved',
            'Payout approved. It is authorised for settlement, and the seller has not been paid yet.',
            $note,
        );
    }

    public function reject(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(RejectPayout::class)(
                $payout, $this->actor($request), $validated['reason'],
            ),
            'payouts.rejected',
            'Payout rejected. The reservation has been released.',
            $validated['reason'],
        );
    }

    public function startSettlement(Request $request, string $reference): RedirectResponse
    {
        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(RetryPayoutSettlement::class)(
                $payout, $this->actor($request), $request->string('method')->toString() ?: 'manual',
            ),
            'payouts.settlement.started',
            'Marked as being settled.',
        );
    }

    /** §27 — recording that money left, which is the only ledger write here. */
    public function settle(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', 'max:40'],
            'reference' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(RecordPayoutSettlement::class)(
                $payout,
                $this->actor($request),
                $validated['method'],
                $validated['reference'],
                $validated['note'] ?? null,
            ),
            'payouts.settled',
            'Settlement recorded. The payout debit is on the seller ledger.',
            $validated['reference'],
        );
    }

    public function fail(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'failure_code' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(FailPayoutSettlement::class)(
                $payout,
                $this->actor($request),
                $validated['reason'],
                $validated['failure_code'] ?? null,
            ),
            'payouts.settlement.failed',
            'Settlement recorded as failed. The money stays reserved until you reject or cancel the request.',
            $validated['reason'],
        );
    }

    public function cancel(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->run(
            $request,
            $reference,
            fn (PayoutRequest $payout): bool => app(CancelPayoutRequest::class)(
                $payout, $this->actor($request), $validated['reason'],
            ),
            'payouts.cancelled',
            'Payout cancelled and the reservation released.',
            $validated['reason'],
        );
    }

    /** §72 — one seller's whole financial picture. */
    public function sellerFinance(Request $request, SellerAccount $seller): Response
    {
        $currency = $request->string('currency')->toString() ?: PayoutPolicy::currency();
        $currency = strtoupper($currency);

        $payouts = PayoutRequest::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PayoutRequest $payout): array => $this->view->summarise($payout))
            ->all();

        return Inertia::render('Sellers/Finance', [
            'seller' => [
                'id' => $seller->public_id,
                'name' => $seller->legal_name,
                'status' => $seller->status->value,
                'statusLabel' => $seller->status->label(),
            ],
            'position' => ($this->position)($seller->id, $currency)->toArray(),
            'statement' => app(BuildSellerStatement::class)($seller->id, $currency, perPage: 25),
            'payouts' => $payouts,
            'currency' => $currency,
            'can' => $this->capabilities($request),
        ]);
    }

    /** §64 — a correction, which is exceptional and always audited. */
    public function adjust(Request $request, SellerAccount $seller): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            app(PostFinancialAdjustment::class)(
                seller: $seller,
                amountMinor: (int) $validated['amount_minor'],
                reason: $validated['reason'],
                actor: $this->actor($request),
                currency: PayoutPolicy::currency(),
            );
        } catch (PayoutNotPermitted $refused) {
            throw ValidationException::withMessages(['amount_minor' => $refused->getMessage()]);
        }

        return back()->with('status', 'Adjustment posted to the seller ledger.');
    }

    // ---------------------------------------------------------------

    /**
     * @param  callable(PayoutRequest): bool  $operation
     */
    private function run(
        Request $request,
        string $reference,
        callable $operation,
        string $auditAction,
        string $message,
        ?string $reason = null,
    ): RedirectResponse {
        $payout = $this->findOrFail($reference);

        try {
            $moved = $operation($payout);
        } catch (PayoutNotPermitted $refused) {
            throw ValidationException::withMessages([
                $refused->reason === 'reason_required' ? 'reason' : 'payout' => $refused->getMessage(),
            ]);
        }

        if ($moved) {
            ($this->audit)(
                action: $auditAction,
                actorType: 'admin',
                actorId: $this->actorId($request),
                subjectType: 'payout_request',
                subjectId: $payout->id,
                changes: [
                    'reference' => $payout->reference,
                    'amount_minor' => $payout->amount_minor,
                    'currency' => $payout->currency,
                    'status' => $payout->refresh()->status->value,
                ],
                reason: $reason,
            );
        }

        return back()->with('status', $moved ? $message : 'Nothing to do — that has already been done.');
    }

    private function findOrFail(string $reference): PayoutRequest
    {
        /** @var PayoutRequest|null $payout */
        $payout = PayoutRequest::query()
            ->withoutGlobalScopes()
            ->where('reference', $reference)
            ->first();

        if ($payout === null) {
            throw new NotFoundHttpException;
        }

        return $payout;
    }

    private function actor(Request $request): PayoutActor
    {
        $admin = $request->user('admin');

        return PayoutActor::admin(
            $this->actorId($request),
            $admin instanceof AdminUser ? $admin->name : null,
        );
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user('admin')?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    private function may(Request $request, AdminPermission $permission): bool
    {
        return $request->user('admin')?->can($permission->value) === true;
    }

    /** @return array<string, bool> */
    private function capabilities(Request $request): array
    {
        return [
            'review' => $this->may($request, AdminPermission::ReviewPayouts),
            'approve' => $this->may($request, AdminPermission::ApprovePayouts),
            'reject' => $this->may($request, AdminPermission::RejectPayouts),
            'settle' => $this->may($request, AdminPermission::SettlePayouts),
            'viewSensitive' => $this->may($request, AdminPermission::ViewPayoutsSensitive),
            'adjust' => $this->may($request, AdminPermission::AdjustSellerFinance),
        ];
    }
}
