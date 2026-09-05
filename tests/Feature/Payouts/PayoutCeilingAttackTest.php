<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Data\SellerFinancialPosition;
use App\Modules\Payouts\Enums\PayoutIneligibility;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutAllocation;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\EvaluatePayoutEligibility;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

/**
 * M9 property 3 — a payout can never exceed the authoritative
 * withdrawable balance.
 *
 * The formula is fixed and is repeated here because every test below is a
 * statement about it:
 *
 *     net_balance          = pending + clearing + available   (SIGNED)
 *     raw_payout_capacity  = min(available, net_balance) - reserved
 *                                                            (SIGNED)
 *     withdrawable_balance = max(0, raw_payout_capacity)
 *                                                            (NEVER < 0)
 *
 * Two predicates that look alike and are not:
 *
 *     isNegative()  net_balance < 0          — the platform owes nothing;
 *                                              the seller owes the platform
 *     isShort()     raw_capacity < 0         — the seller may be owed money
 *                                              and still have no capacity,
 *                                              because a hold is standing
 *                                              against funds a refund took
 *
 * A seller can be not-negative and short at the same time, and collapsing
 * the two would either block sellers who are solvent or pay out sellers
 * who are not.
 *
 * As in property 2, every refused request asserts two separate things:
 *
 *     THE REQUEST IS REFUSED
 *     AND NOTHING WAS HELD, POSTED OR PROMISED.
 *
 * An exception that nevertheless wrote an allocation has reserved money
 * against a seller who was told no, and every later request is short by
 * that amount with nothing on any screen to explain it.
 */
final class PayoutCeilingAttackTest extends TestCase
{
    use BuildsSellerFinance;
    use ObservesPayoutTruth;
    use RefreshDatabase;

    private function seller(string $label = 'a'): SellerAccount
    {
        ['seller' => $seller] = $this->makeSeller(SellerRole::Owner, [
            'status' => SellerStatus::Approved->value,
            'legal_name' => "Store {$label}",
        ]);

        $this->destination($seller);

        return $seller;
    }

    /**
     * Ask for a payout and expect a refusal, proving nothing moved.
     *
     * @return PayoutNotPermitted the refusal, so a caller can assert on it
     */
    private function assertRefused(SellerAccount $seller, int $amountMinor, string $attack, string $currency = 'USD'): PayoutNotPermitted
    {
        $before = $this->payoutTruth($seller);
        Notification::fake();

        try {
            $this->requestPayout($seller, $amountMinor, $currency);
            $this->fail("{$attack}: a payout of {$amountMinor} was allowed.");
        } catch (PayoutNotPermitted $refusal) {
            $this->assertPayoutTruthUnchanged($before, $seller, $attack);
            Notification::assertNothingSent();

            return $refusal;
        }
    }

    // ── §3 — the boundary, exactly ────────────────────────────────────

    #[Test]
    public function the_ceiling_is_the_withdrawable_balance_to_the_minor_unit(): void
    {
        $seller = $this->seller();
        $this->availableEarning($seller, 10_000);

        $position = $this->positionOf($seller);

        $this->assertSame(10_000, $position->availableMinor);
        $this->assertSame(10_000, $position->netBalanceMinor());
        $this->assertSame(0, $position->reservedMinor);
        $this->assertSame(10_000, $position->withdrawableMinor());

        // One minor unit over is over.
        $this->assertRefused($seller, 10_001, 'one unit above the ceiling');

        // And the ceiling itself is reachable — a boundary that refuses
        // its own value is a different bug wearing the same green tick.
        $payout = $this->requestPayout($seller, 10_000);

        $this->assertSame(10_000, $payout->amount_minor);
        $this->assertSame(0, $this->positionOf($seller)->withdrawableMinor());
    }

    // ── §4 and §5 — money that is not yet money ───────────────────────

    #[Test]
    public function clearing_money_cannot_be_withdrawn_before_the_clearing_job_moves_it(): void
    {
        $seller = $this->seller();
        $this->clearingEarning($seller, 10_000);

        $position = $this->positionOf($seller);

        $this->assertSame(0, $position->availableMinor);
        $this->assertSame(10_000, $position->clearingMinor);
        $this->assertSame(10_000, $position->netBalanceMinor(), 'The platform does owe it.');

        // min(available, net) is the §48 cap doing its job: the seller is
        // owed 10,000 and may withdraw none of it.
        $this->assertSame(0, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor());
        $this->assertFalse($position->isNegative());
        $this->assertFalse($position->isShort());

        $this->assertRefused($seller, 1, 'a penny against clearing money');
        $this->assertRefused($seller, 10_000, 'the whole clearing balance');
    }

    #[Test]
    public function pending_money_is_not_withdrawable_before_delivery(): void
    {
        $seller = $this->seller();
        $this->pendingEarning($seller, 10_000);

        $position = $this->positionOf($seller);

        $this->assertSame(0, $position->availableMinor);
        $this->assertSame(10_000, $position->pendingMinor);
        $this->assertSame(0, $position->withdrawableMinor());

        $this->assertRefused($seller, 1, 'a penny against pending money');
    }

    // ── §6 — a hold reduces capacity ──────────────────────────────────

    #[Test]
    public function an_open_reservation_reduces_capacity_by_exactly_its_amount(): void
    {
        $seller = $this->seller();
        $this->availableEarning($seller, 10_000);
        $this->requestPayout($seller, 8_000);

        $position = $this->positionOf($seller);

        $this->assertSame(10_000, $position->availableMinor, 'A hold is not a ledger movement.');
        $this->assertSame(8_000, $position->reservedMinor);
        $this->assertSame(2_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(2_000, $position->withdrawableMinor());

        // The arithmetic is asserted directly rather than only through a
        // second request, because Phase 1 allows one open request per
        // seller — and a state-machine rule that refuses everything would
        // hide a capacity formula that had stopped subtracting the hold.
        $this->assertSame(
            2_000,
            min($position->availableMinor, $position->netBalanceMinor()) - $position->reservedMinor,
        );

        // Refused on the state-machine rule rather than the arithmetic,
        // which is exactly why the arithmetic is asserted directly above.
        $refusal = $this->assertRefused($seller, 2_001, 'above the reduced capacity');
        $this->assertSame(PayoutIneligibility::OpenPayoutExists, $refusal->ineligibility);
    }

    // ── §7 — a negative overall position ──────────────────────────────

    #[Test]
    public function an_available_earning_is_not_authority_when_the_position_is_negative(): void
    {
        $seller = $this->seller();

        // Legitimate history: earned, then reversed by more than was
        // earned because a refund landed against money already sent.
        $this->availableEarning($seller, 5_000);
        $this->reversal($seller, 12_000);

        $position = $this->positionOf($seller);

        $this->assertSame(-7_000, $position->availableMinor);
        $this->assertSame(-7_000, $position->netBalanceMinor());
        $this->assertTrue($position->isNegative());
        $this->assertTrue($position->isShort());
        $this->assertSame(-7_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor(), 'Clamped, never negative.');

        // A single positive AVAILABLE row still exists in the ledger.
        // It is not authority; the aggregate is.
        $this->assertTrue(
            SellerLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('seller_account_id', $seller->id)
                ->where('status', LedgerEntryStatus::Available->value)
                ->where('amount_minor', '>', 0)
                ->exists(),
        );

        $this->assertRefused($seller, 1, 'a payout against a negative position');
        $this->assertRefused($seller, 5_000, 'a payout matching one positive row');
    }

    // ── §8 — short, but not negative ──────────────────────────────────

    #[Test]
    public function a_seller_can_be_short_without_being_negative(): void
    {
        $seller = $this->seller();

        // The M7 edge case, reached the way it actually happens: money
        // earned, a payout opened against it, and then a refund landing
        // while the hold is still standing.
        $this->availableEarning($seller, 10_000);
        $this->requestPayout($seller, 10_000);
        $this->reversal($seller, 6_000);

        $position = $this->positionOf($seller);

        $this->assertSame(4_000, $position->availableMinor);
        $this->assertSame(4_000, $position->netBalanceMinor());
        $this->assertSame(10_000, $position->reservedMinor);

        // The two predicates disagree, and both are right. The platform
        // still owes this store 4,000 — they are not in debt — and yet
        // there is 6,000 more held than exists to hold.
        $this->assertFalse($position->isNegative(), 'The platform still owes this seller money.');
        $this->assertTrue($position->isShort(), 'And there is more held than there is to hold.');

        $this->assertSame(-6_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor());

        $this->assertRefused($seller, 1, 'a payout while short');
    }

    // ── §9 — the clamp, in the domain and on the wire ──────────────────

    #[Test]
    public function withdrawable_is_never_negative_in_any_reachable_state(): void
    {
        // Every shape currently capable of producing a negative raw
        // capacity, asserted against the type rather than through the
        // database, so the clamp is proved as a property of the formula.
        $shapes = [
            'refund past zero' => new SellerFinancialPosition('USD', 0, 0, -7_000, 0, 0),
            'hold exceeds funds' => new SellerFinancialPosition('USD', 0, 0, 4_000, 10_000, 0),
            'negative and held' => new SellerFinancialPosition('USD', 0, 0, -1_000, 500, 0),
            'clearing hides a debt' => new SellerFinancialPosition('USD', 0, 9_000, -9_500, 0, 0),
            'pending hides a debt' => new SellerFinancialPosition('USD', 9_000, 0, -9_500, 0, 0),
            'everything at once' => new SellerFinancialPosition('USD', 1_000, 2_000, -8_000, 3_000, 40_000),
            'exactly zero capacity' => new SellerFinancialPosition('USD', 0, 0, 5_000, 5_000, 0),
        ];

        foreach ($shapes as $label => $position) {
            $this->assertGreaterThanOrEqual(
                0,
                $position->withdrawableMinor(),
                "{$label}: withdrawable went below zero.",
            );

            $this->assertSame(
                max(0, min($position->availableMinor, $position->netBalanceMinor()) - $position->reservedMinor),
                $position->withdrawableMinor(),
                "{$label}: the formula drifted.",
            );

            // The serialized shape a page receives.
            $wire = $position->toArray();

            $this->assertGreaterThanOrEqual(0, $wire['withdrawableMinor'], "{$label}: negative on the wire.");
            $this->assertStringNotContainsString(
                '-',
                (string) $wire['withdrawable'],
                "{$label}: a minus sign reached the string a page prints beside \"available to withdraw\".",
            );

            // And the signed figures stay signed, because an operator
            // answering "when can I withdraw again" needs to know how far
            // short the store is, which a clamped zero cannot say.
            $this->assertSame($position->rawPayoutCapacityMinor(), $wire['rawPayoutCapacityMinor']);
            $this->assertSame($position->netBalanceMinor(), $wire['netBalanceMinor']);
            $this->assertSame($position->isShort(), $wire['isShort']);
            $this->assertSame($position->isNegative(), $wire['isNegative']);
        }
    }

    // ── §10 — one open request ────────────────────────────────────────

    #[Test]
    public function a_second_open_request_is_refused_by_the_domain_not_the_interface(): void
    {
        $seller = $this->seller();
        $this->availableEarning($seller, 20_000);

        $this->requestPayout($seller, 5_000);

        // Well within the remaining 15,000, so this is the state machine
        // refusing rather than the arithmetic.
        $refusal = $this->assertRefused($seller, 1_000, 'a second open request');
        $this->assertSame(PayoutIneligibility::OpenPayoutExists, $refusal->ineligibility);

        $this->assertSame(1, PayoutRequest::query()->withoutGlobalScopes()->count());
    }

    // ── §15 — no double subtraction at settlement ─────────────────────

    #[Test]
    public function settlement_subtracts_the_payout_once_and_not_the_hold_as_well(): void
    {
        // The brief's 500/400/100 shape, scaled past the configured
        // minimum so the request reaches the arithmetic rather than being
        // refused before it.
        $seller = $this->seller();
        $earning = $this->availableEarning($seller, 12_000);

        $payout = $this->requestPayout($seller, 7_500);

        $before = $this->positionOf($seller);
        $this->assertSame(12_000, $before->availableMinor);
        $this->assertSame(7_500, $before->reservedMinor);
        $this->assertSame(4_500, $before->withdrawableMinor());

        $this->settle($payout);

        $after = $this->positionOf($seller);

        // The earning is untouched history; the payout is one debit
        // against it; the hold has closed rather than been subtracted a
        // second time. 100, not -300.
        $this->assertTrue($earning->refresh()->exists);
        $this->assertSame(12_000, (int) $earning->amount_minor, 'The earning is history and stays as written.');
        $this->assertSame(12_000 - 7_500, $after->availableMinor);
        $this->assertSame(0, $after->reservedMinor, 'The hold closed rather than persisting alongside the debit.');
        $this->assertSame(4_500, $after->netBalanceMinor());

        // 4,500 — not -3,000, which is what subtracting both the hold and
        // the debit would produce. That double subtraction is the specific
        // bug this arithmetic is shaped to prevent.
        $this->assertSame(4_500, $after->withdrawableMinor());
        $this->assertNotSame(-3_000, $after->rawPayoutCapacityMinor());

        $debits = SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->where('payout_request_id', $payout->id)
            ->get();

        $this->assertCount(1, $debits, 'Exactly one payout debit.');
        $this->assertSame(-7_500, (int) $debits->first()?->amount_minor);
    }

    // ── §19 — what the client may say, and what it may not ────────────

    #[Test]
    public function a_seller_supplies_an_amount_and_nothing_else_about_their_balance(): void
    {
        // The action's signature is the boundary. It takes what to
        // withdraw and never what is withdrawable — the day it accepts an
        // available, net or reserved figure from a caller, the caller
        // becomes the authority on it, and the caller is one refactor away
        // from being a controller.
        $parameters = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(RequestPayout::class, '__invoke'))->getParameters(),
        );

        $this->assertSame(
            ['seller', 'amountMinor', 'currency', 'actor', 'actorMayRequest', 'payoutAccountId'],
            $parameters,
        );

        foreach (['available', 'net', 'reserved', 'balance', 'withdrawable', 'capacity'] as $forbidden) {
            foreach ($parameters as $parameter) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $parameter,
                    "RequestPayout accepts \"{$parameter}\" — a balance figure from its caller.",
                );
            }
        }
    }

    #[Test]
    public function two_currencies_are_two_ceilings_and_never_one_pool(): void
    {
        $seller = $this->seller();

        $this->availableEarning($seller, 10_000, 'USD');
        $this->availableEarning($seller, 10_000, 'EUR');

        $usd = $this->positionOf($seller, 'USD');
        $eur = $this->positionOf($seller, 'EUR');

        $this->assertSame(10_000, $usd->withdrawableMinor());
        $this->assertSame(10_000, $eur->withdrawableMinor());

        // Not 20,000 in either. Aggregating across currencies would let a
        // seller withdraw dollars against euros at an implied rate of one.
        $this->assertRefused($seller, 20_000, 'cross-currency aggregation', 'USD');
        $this->assertRefused($seller, 10_001, 'one unit of cross-currency spill', 'EUR');
    }

    // ── §24 — the request is a seller's own, and an owner's ────────────

    #[Test]
    public function a_suspended_seller_cannot_open_a_new_request(): void
    {
        $seller = $this->seller();
        $this->availableEarning($seller, 10_000);

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $refusal = $this->assertRefused($seller->refresh(), 5_000, 'a suspended seller');
        $this->assertNotSame('', $refusal->getMessage());
    }

    #[Test]
    public function an_actor_without_the_payout_permission_is_refused(): void
    {
        $seller = $this->seller();
        $this->availableEarning($seller, 10_000);

        $before = $this->payoutTruth($seller);
        Notification::fake();

        try {
            app(RequestPayout::class)(
                seller: $seller,
                amountMinor: 5_000,
                currency: 'USD',
                actor: PayoutActor::seller(null, 'Finance manager'),
                actorMayRequest: false,
            );
            $this->fail('A non-owner opened a payout request.');
        } catch (PayoutNotPermitted) {
            // M7's owner-only policy, unchanged. M9 does not widen it.
        }

        $this->assertPayoutTruthUnchanged($before, $seller, 'actor without permission');
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_eligibility_service_answers_for_the_seller_it_is_given(): void
    {
        $rich = $this->seller('rich');
        $poor = $this->seller('poor');

        $this->availableEarning($rich, 50_000);

        $evaluate = app(EvaluatePayoutEligibility::class);

        $this->assertTrue($evaluate($rich, 'USD')->canRequest);
        $this->assertFalse($evaluate($poor, 'USD')->canRequest);

        // A seller id is not a claim a caller gets to make about somebody
        // else's money: the poor seller's eligibility is computed from the
        // poor seller's ledger however the question is phrased.
        $this->assertSame(50_000, $evaluate($rich, 'USD')->withdrawableMinor);
        $this->assertSame(0, $evaluate($poor, 'USD')->withdrawableMinor);
    }

    // ── §18 — one seller's trouble is not another's ───────────────────

    #[Test]
    public function nothing_that_happens_to_one_seller_reaches_another(): void
    {
        $a = $this->seller('a');
        $b = $this->seller('b');

        $this->availableEarning($a, 20_000);
        $this->availableEarning($b, 20_000);

        $untouched = $this->payoutTruth($b);

        // A does everything: requests, settles, is refunded past zero,
        // then is refused a further payout.
        $payout = $this->requestPayout($a, 15_000);
        $this->settle($payout);
        $this->reversal($a, 18_000);

        $this->assertTrue($this->positionOf($a)->isNegative());
        $this->assertRefused($a, 1, 'a payout by a negative seller');

        // B has not moved: not their ledger, their capacity, their
        // reservations or their history.
        $this->assertPayoutTruthUnchanged($untouched, $b, 'seller B during seller A\'s trouble');
        $this->assertSame(20_000, $this->positionOf($b)->withdrawableMinor());

        // And B can still withdraw, which is the half a fingerprint alone
        // would not catch: a global lock that froze everybody would leave
        // B's rows identical too.
        $this->assertSame(20_000, $this->requestPayout($b, 20_000)->amount_minor);
    }

    #[Test]
    public function the_minimum_is_policy_and_not_a_literal(): void
    {
        $seller = $this->seller();
        $minimum = PayoutPolicy::minimumMinor();

        $this->availableEarning($seller, $minimum + 1_000);

        $this->assertGreaterThan(0, $minimum, 'This test is vacuous with no minimum configured.');
        $this->assertRefused($seller, max(1, $minimum - 1), 'below the configured minimum');

        $this->assertSame($minimum, $this->requestPayout($seller, $minimum)->amount_minor);
    }

    // ── §9 on the wire, and §20 the boundary ──────────────────────────

    #[Test]
    public function a_short_seller_is_never_shown_a_negative_amount_to_withdraw(): void
    {
        // The clamp, on the props a real page actually receives, from a
        // real request. The type-level proof above is the property; this
        // is the delivery.
        ['seller' => $seller, 'user' => $user] = $this->makeSeller(SellerRole::Owner, [
            'status' => SellerStatus::Approved->value,
        ]);
        $this->destination($seller);

        $this->availableEarning($seller, 10_000);
        $this->requestPayout($seller, 10_000);
        $this->reversal($seller, 6_000);

        $props = $this->actingAs($user)->get('/seller/payouts')->viewData('page')['props'];

        $position = $props['position'];

        $this->assertSame(0, $position['withdrawableMinor']);
        $this->assertStringNotContainsString('-', (string) $position['withdrawable']);

        // The signed truth is still there for anybody who needs it — a
        // store asking "when can I withdraw again" needs the -6,000, and a
        // clamped zero cannot tell them.
        $this->assertSame(-6_000, $position['rawPayoutCapacityMinor']);
        $this->assertSame(4_000, $position['netBalanceMinor']);
        $this->assertTrue($position['isShort']);
        $this->assertFalse($position['isNegative']);

        // And the page is told the answer rather than the ingredients to
        // compute it from.
        $this->assertArrayHasKey('eligibility', $props);
        $this->assertFalse($props['eligibility']['canRequest']);
        $this->assertNotNull($props['eligibility']['reason']);
    }

    #[Test]
    public function no_payout_capacity_formula_exists_outside_the_domain(): void
    {
        /*
         * The formula lives in exactly one place. That is worth enforcing
         * rather than trusting, because the duplicate is always written
         * innocently — a controller that wants to show "after this payout",
         * a React page that wants to grey out a button — and the duplicate
         * is what drifts. When it drifts, one of the two numbers is wrong
         * and the wrong one is usually the one a seller is looking at.
         *
         * Reads are fine. Arithmetic is not.
         */
        $forbidden = [
            // A balance figure with an operator next to it, either side.
            '/\b\w*(available|reserved|net|withdrawable|capacity)\w*[Mm]inor\b\s*[-+*\/]/i',
            '/[-+*\/]\s*\$?[\w>()\-]*\b\w*(available|reserved)\w*[Mm]inor\b/i',
            // Or a clamp of its own.
            '/\b(min|max|Math\.min|Math\.max)\s*\(\s*[^)]*(available|reserved|withdrawable|netBalance)/i',
        ];

        $checked = 0;

        foreach ($this->payoutSurfaceFiles() as $file) {
            $checked++;
            $source = (string) file_get_contents($file);
            $relative = str_replace(base_path().'/', '', $file);

            foreach ($forbidden as $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $source),
                    "{$relative} computes payout capacity. There is one formula, and it lives in "
                        .'SellerFinancialPosition — a surface that recomputes it will drift from it, '
                        .'and the seller will be looking at whichever copy is wrong.',
                );
            }
        }

        $this->assertGreaterThan(6, $checked, 'The scan found almost nothing; it is not looking where it should.');
    }

    /**
     * The controllers and pages that show payout money.
     *
     * @return array<int, string>
     */
    private function payoutSurfaceFiles(): array
    {
        $files = [];

        foreach ([
            app_path('Modules/Payouts/Http'),
            app_path('Modules/AdminPortal/Http'),
            resource_path('js/seller/pages/Payouts'),
            resource_path('js/admin/pages/Payouts'),
        ] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory))) as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'tsx', 'ts'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach ([
            resource_path('js/seller/pages/Earnings.tsx'),
            resource_path('js/admin/pages/Sellers/Finance.tsx'),
            resource_path('js/admin/pages/Finance/Index.tsx'),
        ] as $page) {
            if (is_file($page)) {
                $files[] = $page;
            }
        }

        sort($files);

        return $files;
    }

    #[Test]
    public function an_allocation_is_never_written_for_a_refused_request(): void
    {
        $seller = $this->seller();
        $this->availableEarning($seller, 1_000);

        $this->assertRefused($seller, 900_000, 'a wildly excessive request');

        $this->assertSame(0, PayoutRequest::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, PayoutAllocation::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()->where('seller_account_id', $seller->id)->count(),
            'Only the earning. No debit, no adjustment, nothing.',
        );
    }
}
