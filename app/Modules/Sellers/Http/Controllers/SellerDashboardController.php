<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Payouts\Queries\EvaluatePayoutEligibility;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller's landing screen.
 *
 * M1 kept it to "who am I, am I approved, and what is left to set up",
 * because there was no trading and inventing figures would have been worse
 * than an empty screen. There is trading now, so it answers the two
 * questions a seller opens it for: what needs doing, and where is my
 * money.
 *
 * Every number is counted in SQL — six aggregates for the page, none per
 * row — and every one is real. There is still nothing here the data
 * cannot support: no charts of trends nobody has a second data point for,
 * no conversion rate the platform does not measure.
 *
 * The money comes from the ledger rather than from the orders, and the
 * three states are kept apart on purpose. A seller shown one "earnings"
 * figure would reasonably assume they can spend it; pending, clearing and
 * available are three different promises.
 */
final class SellerDashboardController
{
    public function __construct(
        private readonly GetSellerFinancialPosition $position,
        private readonly EvaluatePayoutEligibility $eligibility,
    ) {}

    public function __invoke(): Response
    {
        $membership = CurrentSeller::membership();
        $seller = $membership?->sellerAccount;

        abort_if($seller === null, 404);

        $store = Store::query()->where('seller_account_id', $seller->id)->first();

        // Setup steps are read from the record, not tracked in a separate
        // "onboarding progress" column that can drift out of step with it.
        $hasStore = $store !== null;

        $steps = [
            [
                'key' => 'store',
                'label' => 'Name your store and claim its address',
                'done' => $hasStore,
                'href' => '/seller/store',
            ],
            [
                'key' => 'branding',
                'label' => 'Upload a logo and banner',
                'done' => $hasStore && $store->logo_media_id !== null && $store->banner_media_id !== null,
                'href' => '/seller/store',
            ],
            [
                'key' => 'policies',
                'label' => 'Write your shipping and return policies',
                'done' => $hasStore && $store->shipping_policy !== null && $store->return_policy !== null,
                'href' => '/seller/store',
            ],
            [
                'key' => 'team',
                'label' => 'Invite the people who will run the store',
                'done' => $seller->memberships()->count() > 1,
                'href' => '/seller/team',
            ],
        ];

        $canSeeFinance = CurrentSeller::can(SellerPermission::FinanceView);
        $canSeeOrders = CurrentSeller::can(SellerPermission::OrdersView);

        return Inertia::render('Dashboard', [
            'fulfilment' => $canSeeOrders ? $this->fulfilmentCounts($seller->id) : null,
            'earnings' => $canSeeFinance ? $this->earnings($seller) : null,
            'seller' => [
                'legalName' => $seller->legal_name,
                'reference' => $seller->public_id,
                'status' => $seller->status->value,
                'role' => $membership->role->value,
                'roleLabel' => $membership->role->label(),
            ],
            'store' => $store === null ? null : [
                'name' => $store->name,
                'slug' => $store->slug,
                'isOpen' => $store->is_open,
                'publicUrl' => rtrim((string) config('veritas.identity.public_url'), '/').'/stores/'.$store->slug,
            ],
            'setup' => $steps,
            'can' => [
                'manageStore' => CurrentSeller::can(SellerPermission::StoreManage),
                'manageMembers' => CurrentSeller::can(SellerPermission::MembersManage),
                'seeFinance' => $canSeeFinance,
                'seeOrders' => $canSeeOrders,
                'seePayouts' => CurrentSeller::can(SellerPermission::PayoutsView),
            ],
        ]);
    }

    /**
     * What is waiting to be done, counted in one grouped query.
     *
     * The states a seller acts on, not every state that exists: an order
     * that is completed or refunded is not work, and listing it here would
     * bury the work that is.
     *
     * @return array<string, int>
     */
    private function fulfilmentCounts(int $sellerAccountId): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = DB::table('seller_orders')
            ->where('seller_account_id', $sellerAccountId)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $of = static fn (SellerOrderStatus ...$statuses): int => array_sum(array_map(
            static fn (SellerOrderStatus $status): int => $byStatus[$status->value] ?? 0,
            $statuses,
        ));

        $lowStock = (int) DB::table('inventory_balances')
            ->join('offers', 'offers.id', '=', 'inventory_balances.offer_id')
            ->where('offers.seller_account_id', $sellerAccountId)
            ->whereRaw('inventory_balances.available <= ?', [
                (int) config('veritas.inventory.low_stock_threshold'),
            ])
            ->count();

        return [
            'awaitingConfirmation' => $of(SellerOrderStatus::Paid),
            'preparing' => $of(SellerOrderStatus::Confirmed, SellerOrderStatus::Processing, SellerOrderStatus::Packed),
            'inTransit' => $of(SellerOrderStatus::PartiallyShipped, SellerOrderStatus::Shipped, SellerOrderStatus::PartiallyDelivered),
            'delivered' => $of(SellerOrderStatus::Delivered),
            'completed' => $of(SellerOrderStatus::Completed),
            'lowStock' => $lowStock,
        ];
    }

    /**
     * The seller's money, and whether they can ask for any of it.
     *
     * Both come from the domain: the five figures from
     * GetSellerFinancialPosition, and can-they-withdraw from
     * EvaluatePayoutEligibility — the same service RequestPayout consults
     * inside its transaction, so the button and the action cannot disagree.
     *
     * M6's `payoutsAvailable => false` is gone. It was honest then and
     * would be a lie now; what replaces it is a real answer with a real
     * reason attached, and React renders it rather than deciding it (§17).
     *
     * @return array<string, mixed>
     */
    private function earnings(SellerAccount $seller): array
    {
        $currency = PayoutPolicy::currency();
        $position = ($this->position)($seller->id, $currency);

        $nextRelease = DB::table('seller_ledger_entries')
            ->where('seller_account_id', $seller->id)
            ->where('currency', $currency)
            ->where('status', LedgerEntryStatus::Clearing->value)
            ->whereNotNull('available_at')
            ->min('available_at');

        return array_merge($position->toArray(), [
            'nextReleaseAt' => is_string($nextRelease)
                ? Carbon::parse($nextRelease)->toIso8601String()
                : null,
            'eligibility' => ($this->eligibility)(
                $seller,
                $currency,
                CurrentSeller::can(SellerPermission::PayoutsRequest),
                $position,
            )->toArray(),
        ]);
    }
}
