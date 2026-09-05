<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Actions\CancelPayoutRequest;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Actions\SavePayoutDestination;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Data\PayoutEligibility;
use App\Modules\Payouts\Data\SellerFinancialPosition;
use App\Modules\Payouts\Enums\PayoutAccountType;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\BuildPayoutView;
use App\Modules\Payouts\Queries\BuildSellerStatement;
use App\Modules\Payouts\Queries\EvaluatePayoutEligibility;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The seller's money: what they have, what they asked for, where it goes.
 *
 * Orchestration only. Not one financial rule lives here — the position
 * comes from GetSellerFinancialPosition, whether a payout may be requested
 * comes from EvaluatePayoutEligibility, and what happens when one is comes
 * from RequestPayout. §93's "no controller finance logic" is the whole
 * shape of this file: find the seller, validate the shape, call the
 * action, say what happened.
 *
 * The seller is always the one the membership resolves to. Nothing here
 * takes a seller id from the request, so there is no seller id to tamper
 * with (§27), and a payout is looked up scoped to that seller so another
 * store's reference 404s rather than 403s — the same rule the rest of the
 * portal follows, and the one that tells an attacker nothing.
 */
final class SellerFinanceController
{
    public function __construct(
        private readonly GetSellerFinancialPosition $position,
        private readonly EvaluatePayoutEligibility $eligibility,
        private readonly BuildPayoutView $view,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** The statement: every ledger row, with the position above it. */
    public function earnings(Request $request): Response
    {
        $seller = $this->seller();
        $currency = PayoutPolicy::currency();

        // Read once and handed to both: the figures on the screen and the
        // eligibility answer beside them must come from one balance.
        $position = ($this->position)($seller->id, $currency);

        return Inertia::render('Earnings', [
            'position' => $position->toArray(),
            'statement' => app(BuildSellerStatement::class)(
                $seller->id,
                $currency,
                perPage: 25,
            ),
            'eligibility' => $this->eligibilityFor($seller, $currency, $position)->toArray(),
            'can' => $this->capabilities(),
        ]);
    }

    /** The payout list, and the one open request if there is one. */
    public function index(): Response
    {
        $seller = $this->seller();
        $currency = PayoutPolicy::currency();

        $payouts = PayoutRequest::query()
            ->where('seller_account_id', $seller->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PayoutRequest $payout): array => $this->view->summarise($payout))
            ->all();

        $position = ($this->position)($seller->id, $currency);

        return Inertia::render('Payouts/Index', [
            'position' => $position->toArray(),
            'eligibility' => $this->eligibilityFor($seller, $currency, $position)->toArray(),
            'payouts' => $payouts,
            'destination' => $this->destinationFor($seller),
            'can' => $this->capabilities(),
        ]);
    }

    public function show(string $reference): Response
    {
        $payout = $this->ownOrFail($reference);

        return Inertia::render('Payouts/Show', [
            // Never with sensitive destination metadata: the seller sees
            // the label they chose, which is what identifies it to them.
            'payout' => $this->view->detail($payout),
            'can' => $this->capabilities(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $seller = $this->seller();

        /*
         * The amount is the only thing taken from the browser, and even
         * its maximum is not: `max` here would be a courtesy message, and
         * the real cap is applied inside RequestPayout against a balance
         * read under a lock. §9.
         */
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $payout = app(RequestPayout::class)(
                seller: $seller,
                amountMinor: (int) $validated['amount_minor'],
                currency: PayoutPolicy::currency(),
                actor: $this->actor($request),
                actorMayRequest: CurrentSeller::allows($this->membership(), SellerPermission::PayoutsRequest),
            );
        } catch (PayoutNotPermitted $refused) {
            throw ValidationException::withMessages(['amount_minor' => $refused->getMessage()]);
        }

        ($this->audit)(
            action: 'payouts.requested',
            actorType: 'seller_user',
            actorId: $this->actorId($request),
            subjectType: 'payout_request',
            subjectId: $payout->id,
            changes: [
                'reference' => $payout->reference,
                'amount_minor' => $payout->amount_minor,
                'currency' => $payout->currency,
            ],
        );

        return redirect()
            ->route('seller.payouts.show', $payout->reference)
            ->with('status', "Payout {$payout->reference} requested for ".$payout->amount()->format().'.');
    }

    public function cancel(Request $request, string $reference): RedirectResponse
    {
        $payout = $this->ownOrFail($reference);

        try {
            app(CancelPayoutRequest::class)(
                $payout,
                $this->actor($request),
                $request->string('reason')->toString() ?: null,
            );
        } catch (PayoutNotPermitted $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        ($this->audit)(
            action: 'payouts.cancelled',
            actorType: 'seller_user',
            actorId: $this->actorId($request),
            subjectType: 'payout_request',
            subjectId: $payout->id,
            changes: ['reference' => $payout->reference],
        );

        return back()->with('status', 'Payout request cancelled. The money is available again.');
    }

    /**
     * Changing where the money goes.
     *
     * §59: the password is asked for again even though the seller is
     * already signed in, because this is the one seller action whose
     * whole value to an attacker is that it points money elsewhere. A
     * stolen session is enough to browse; it should not be enough to
     * redirect a withdrawal.
     */
    public function saveDestination(Request $request): RedirectResponse
    {
        $seller = $this->seller();

        $validated = $request->validate([
            'display_label' => ['required', 'string', 'max:120'],
            'last4' => ['nullable', 'string', 'size:4'],
            'country' => ['nullable', 'string', 'size:2'],
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user === null || ! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is not right.',
            ]);
        }

        app(SavePayoutDestination::class)(
            seller: $seller,
            displayLabel: $validated['display_label'],
            actor: $this->actor($request),
            // Phase 1 settles by hand, so this is the only type a seller
            // can choose. The column exists because the next rail will.
            type: PayoutAccountType::Manual,
            last4: $validated['last4'] ?? null,
            country: isset($validated['country']) ? strtoupper((string) $validated['country']) : null,
            currency: PayoutPolicy::currency(),
        );

        return back()->with('status', 'Payout destination saved. It applies to your next request.');
    }

    // ---------------------------------------------------------------

    /**
     * The membership, resolved once per request.
     *
     * These screens ask three capability questions and look the seller up
     * twice; CurrentSeller::can() re-reads the membership and its account
     * on every call, so asking it six times is twelve queries to answer
     * "what may this person do here". Resolved once, and every question
     * goes to CurrentSeller::allows() — which is still the one place the
     * suspension rule lives.
     */
    /**
     * Resolved every time, never cached on this object.
     *
     * It used to be memoised into a property, which is wrong for a reason
     * that is not visible from here: Laravel caches the controller
     * instance on the Route object, and a Route outlives a request. Under
     * php-fpm the process ends with the request and nothing is noticed.
     * Under any runtime that keeps the application alive between requests
     * — Octane, RoadRunner, Swoole — the second seller to hit this
     * controller is served the first seller's membership, and reads their
     * payouts. M9 reproduced exactly that, in-process, before this line
     * changed.
     *
     * The saving was four indexed single-row lookups on a page nobody
     * loads in a loop. That is not a trade worth a cross-tenant leak, and
     * `ControllerStateTest` now stops the pattern coming back anywhere.
     */
    private function membership(): ?SellerMembership
    {
        return CurrentSeller::membership();
    }

    private function seller(): SellerAccount
    {
        $seller = $this->membership()?->sellerAccount;

        abort_if($seller === null, 404);

        return $seller;
    }

    private function eligibilityFor(
        SellerAccount $seller,
        string $currency,
        ?SellerFinancialPosition $position = null,
    ): PayoutEligibility {
        return ($this->eligibility)(
            $seller,
            $currency,
            CurrentSeller::allows($this->membership(), SellerPermission::PayoutsRequest),
            $position,
        );
    }

    /** @return array<string, mixed>|null */
    private function destinationFor(SellerAccount $seller): ?array
    {
        /** @var PayoutAccount|null $account */
        $account = PayoutAccount::query()
            ->where('seller_account_id', $seller->id)
            ->where('status', PayoutAccount::STATUS_ACTIVE)
            ->first();

        return $account === null ? null : [
            'id' => $account->public_id,
            'label' => $account->snapshotLabel(),
            'type' => $account->type->value,
            'typeLabel' => $account->type->label(),
            'currency' => $account->currency,
            'country' => $account->country,
            'verifiedAt' => $account->verified_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function capabilities(): array
    {
        return [
            'viewPayouts' => CurrentSeller::allows($this->membership(), SellerPermission::PayoutsView),
            'requestPayout' => CurrentSeller::allows($this->membership(), SellerPermission::PayoutsRequest),
            'manageDestination' => CurrentSeller::allows($this->membership(), SellerPermission::PayoutAccountManage),
            'minimum' => Money::of(PayoutPolicy::minimumMinor(), PayoutPolicy::currency())->format(),
            'minimumMinor' => PayoutPolicy::minimumMinor(),
            'currency' => PayoutPolicy::currency(),
        ];
    }

    /** Scoped to the seller's own payouts; anything else does not exist. */
    private function ownOrFail(string $reference): PayoutRequest
    {
        /** @var PayoutRequest|null $payout */
        $payout = PayoutRequest::query()
            ->where('seller_account_id', $this->seller()->id)
            ->where('reference', $reference)
            ->first();

        if ($payout === null) {
            throw new NotFoundHttpException;
        }

        return $payout;
    }

    private function actor(Request $request): PayoutActor
    {
        $user = $request->user();

        return PayoutActor::seller(
            $this->actorId($request),
            // The seller portal's guard is `web`, so this is always a
            // customer-side User — but the container types it as either,
            // and a payout history that recorded an admin's name against a
            // seller's own action would be wrong rather than merely odd.
            $user instanceof User ? $user->fullName() : null,
        );
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
