<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The two questions a seller opens their dashboard for: what needs doing,
 * and where is my money.
 *
 * Both answers are real or absent. The money answer is the one that has to
 * be exactly right — a seller shown a spendable-looking figure that a
 * refund has already taken back will plan around a number that is not
 * theirs.
 */
final class SellerDashboardTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /** @return array{user: User} */
    private function member(int $sellerId, SellerRole $role = SellerRole::Owner): array
    {
        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $sellerId,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return ['user' => $user];
    }

    #[Test]
    public function it_counts_the_work_that_is_actually_waiting(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 40);
        ['user' => $member] = $this->member($seller->id);

        // One waiting to be confirmed, one being prepared, one on its way.
        $awaiting = $this->placeOrder([[$offer, 1]]);
        $this->payFor($awaiting);

        $preparing = $this->placeOrder([[$offer, 1]]);
        $this->payFor($preparing);
        $this->confirm($this->sellerOrderFor($preparing->id));

        $sent = $this->placeOrder([[$offer, 1]]);
        $this->payFor($sent);
        $this->shipEverything($this->sellerOrderFor($sent->id));

        $this->asUser($member)->get('/seller')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fulfilment.awaitingConfirmation', 1)
                ->where('fulfilment.preparing', 1)
                ->where('fulfilment.inTransit', 1)
                ->where('fulfilment.delivered', 0)
                ->where('fulfilment.completed', 0));
    }

    #[Test]
    public function the_three_money_states_are_kept_apart(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 40);
        ['user' => $member] = $this->member($seller->id);

        // One paid and undelivered, one delivered and clearing.
        $pending = $this->placeOrder([[$offer, 1]]);
        $this->payFor($pending);

        $clearing = $this->placeOrder([[$offer, 1]]);
        $this->payFor($clearing);
        $this->deliver($this->shipEverything($this->sellerOrderFor($clearing->id)));

        $this->asUser($member)->get('/seller')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('earnings.pendingMinor', 8_800)
                ->where('earnings.clearingMinor', 8_800)
                ->where('earnings.availableMinor', 0)
                ->has('earnings.nextReleaseAt')
                // §90: no payout anywhere in M6, said in the data too.
                ->where('earnings.payoutsAvailable', false));

        // Once it clears, it moves — and only then.
        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->asUser($member)->get('/seller')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('earnings.clearingMinor', 0)
                ->where('earnings.availableMinor', 8_800));
    }

    #[Test]
    public function a_refund_reduces_the_available_figure_the_seller_sees(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 40);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);
        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id)));

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 2_000, 'quantity' => 0]],
            reason: 'A late complaint resolved with a partial refund.',
        );

        // 12% of 2,000 is 240, so the seller gives back 1,760.
        $this->asUser($member)->get('/seller')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('earnings.availableMinor', 7_040));
    }

    #[Test]
    public function a_member_who_cannot_see_the_money_is_not_shown_it(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 40);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        // A fulfilment manager packs boxes and has no finance permission.
        ['user' => $packer] = $this->member($seller->id, SellerRole::FulfillmentManager);

        $this->asUser($packer)->get('/seller')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.seeFinance', false)
                // Not hidden in the UI: the ledger is never read for them.
                ->where('earnings', null)
                ->where('can.seeOrders', true)
                ->has('fulfilment'));

        // A catalogue manager sees neither.
        ['user' => $catalogue] = $this->member($seller->id, SellerRole::CatalogManager);

        $this->asUser($catalogue)->get('/seller')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fulfilment', null)
                ->where('earnings', null));
    }

    #[Test]
    public function the_dashboard_reads_a_bounded_number_of_queries(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 60);
        ['user' => $member] = $this->member($seller->id);

        $count = function () use ($member): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asUser($member)->get('/seller')->assertOk();
            $queries = count(DB::getRawQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $count();

        // Ten more orders in every state the dashboard counts.
        foreach (range(1, 10) as $_) {
            $order = $this->placeOrder([[$offer, 1]]);
            $this->payFor($order);
            $this->deliver($this->shipEverything($this->sellerOrderFor($order->id)));
        }

        $this->assertSame(
            $one,
            $count(),
            'Dashboard counts are SQL aggregates: ten times the data must not be ten times the queries.',
        );
    }
}
