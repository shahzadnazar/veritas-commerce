<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may read and change stock, over HTTP, with real ids.
 *
 * Every isolation case posts another seller's public id from a signed-in
 * session, because asserting a link was hidden proves nothing about what
 * happens when the request arrives anyway.
 */
final class InventoryAccessTest extends TestCase
{
    use RefreshDatabase;
    use StocksOffers;

    #[Test]
    public function a_seller_sees_only_their_own_stock(): void
    {
        ['seller' => $mine, 'user' => $user] = $this->makeSeller();
        $this->stockedOffer(5, $mine);

        $theirs = SellerAccount::factory()->create();
        $this->stockedOffer(9, $theirs);

        $this->asUser($user)
            ->get('/seller/inventory')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Index')->has('rows.data', 1));
    }

    #[Test]
    public function a_seller_cannot_open_another_sellers_inventory_detail(): void
    {
        ['user' => $user] = $this->makeSeller();

        $theirs = SellerAccount::factory()->create();
        ['offer' => $theirOffer] = $this->stockedOffer(9, $theirs);

        // A real, resolvable public id — just not theirs.
        $this->asUser($user)
            ->get('/seller/inventory/'.$theirOffer->public_id)
            ->assertNotFound();
    }

    #[Test]
    public function a_seller_cannot_adjust_another_sellers_stock(): void
    {
        ['user' => $user] = $this->makeSeller();

        $theirs = SellerAccount::factory()->create();
        ['offer' => $theirOffer, 'balance' => $theirBalance] = $this->stockedOffer(9, $theirs);

        $this->asUser($user)
            ->post('/seller/inventory/'.$theirOffer->public_id.'/adjust', [
                'change' => -9,
                'reason' => InventoryMovementReason::Damaged->value,
            ])
            ->assertNotFound();

        $this->assertSame(9, $theirBalance->refresh()->on_hand);
    }

    #[Test]
    public function a_viewer_reads_stock_and_cannot_change_it(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller(SellerRole::Viewer);
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5, $seller);

        $this->assertTrue(SellerRole::Viewer->can(SellerPermission::InventoryView));
        $this->assertFalse(SellerRole::Viewer->can(SellerPermission::InventoryManage));

        $this->asUser($user)->get('/seller/inventory')->assertOk();

        $this->asUser($user)
            ->post('/seller/inventory/'.$offer->public_id.'/adjust', [
                'change' => 5,
                'reason' => InventoryMovementReason::RestockReceived->value,
            ])
            ->assertForbidden();

        $this->assertSame(5, $balance->refresh()->on_hand);
    }

    #[Test]
    public function a_catalogue_manager_lists_products_but_does_not_count_them(): void
    {
        // §46 exactly: catalogue rights do not carry stock rights.
        $this->assertTrue(SellerRole::CatalogManager->can(SellerPermission::InventoryView));
        $this->assertFalse(SellerRole::CatalogManager->can(SellerPermission::InventoryManage));

        ['seller' => $seller, 'user' => $user] = $this->makeSeller(SellerRole::CatalogManager);
        ['offer' => $offer] = $this->stockedOffer(5, $seller);

        $this->asUser($user)
            ->post('/seller/inventory/'.$offer->public_id.'/adjust', [
                'change' => 100,
                'reason' => InventoryMovementReason::RestockReceived->value,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function an_inventory_manager_adjusts_their_own_stock(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller(SellerRole::InventoryManager);
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5, $seller);

        $this->asUser($user)
            ->post('/seller/inventory/'.$offer->public_id.'/adjust', [
                'change' => 20,
                'reason' => InventoryMovementReason::RestockReceived->value,
            ])
            ->assertRedirect();

        $this->assertSame(25, $balance->refresh()->on_hand);
    }

    #[Test]
    public function a_suspended_seller_cannot_adjust_stock(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5, $seller);

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        // Suspension takes every write and leaves reads: they must still
        // be able to see what they owe customers.
        $this->asUser($user)->get('/seller/inventory')->assertOk();

        $this->asUser($user)
            ->post('/seller/inventory/'.$offer->public_id.'/adjust', [
                'change' => 20,
                'reason' => InventoryMovementReason::RestockReceived->value,
            ])
            ->assertForbidden();

        $this->assertSame(5, $balance->refresh()->on_hand);
    }

    #[Test]
    public function an_adjustment_without_a_reason_is_refused(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->stockedOffer(5, $seller);

        $this->asUser($user)
            ->post('/seller/inventory/'.$offer->public_id.'/adjust', ['change' => -1])
            ->assertSessionHasErrors('reason');
    }

    #[Test]
    public function a_seller_cannot_claim_a_system_reason_for_a_manual_edit(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->stockedOffer(5, $seller);

        // A sale is written by the code that performs one; a form post
        // must not be able to say a sale happened.
        $this->asUser($user)
            ->post('/seller/inventory/'.$offer->public_id.'/adjust', [
                'change' => -1,
                'reason' => InventoryMovementReason::SaleCompleted->value,
            ])
            ->assertSessionHasErrors('reason');
    }

    #[Test]
    public function a_customer_cannot_reach_the_seller_inventory_routes(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        ['offer' => $offer] = $this->stockedOffer(5, $seller);

        $customer = User::factory()->create();

        /*
         * Signed in, but with no membership anywhere — so 404, not 403.
         *
         * The seller portal does not reveal itself to people who are not
         * sellers: "you are not allowed here" would confirm that "here"
         * exists and that this URL is the way in.
         */
        $this->asUser($customer)->get('/seller/inventory')->assertNotFound();
        $this->asUser($customer)->get('/seller/inventory/'.$offer->public_id)->assertNotFound();
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in_rather_than_shown_stock(): void
    {
        $this->get('/seller/inventory')->assertRedirect('/login');
    }

    #[Test]
    public function a_seller_cannot_reach_the_platform_inventory_screens(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->stockedOffer(5, $seller);

        $this->asUser($user)->get('/admin/inventory')->assertRedirect('/admin/login');
        $this->asUser($user)->get('/admin/inventory/'.$offer->public_id)->assertRedirect('/admin/login');
    }

    #[Test]
    public function an_admin_who_may_look_cannot_thereby_adjust(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5, $seller);

        // §47: support and analysts read stock and never change it.
        $this->assertTrue(AdminRole::Support->can(AdminPermission::InventoryView));
        $this->assertFalse(AdminRole::Support->can(AdminPermission::InventoryAdjust));

        $support = $this->makeAdmin(AdminRole::Support);

        $this->asAdmin($support)->get('/admin/inventory')->assertOk();

        $this->asAdmin($support)
            ->post('/admin/inventory/'.$offer->public_id.'/adjust', [
                'change' => -5,
                'reason' => 'Because I can.',
            ])
            ->assertForbidden();

        $this->assertSame(5, $balance->refresh()->on_hand);
    }

    #[Test]
    public function a_platform_adjustment_requires_a_written_reason(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        ['offer' => $offer] = $this->stockedOffer(5, $seller);

        $this->asAdmin($this->makeAdmin(AdminRole::MarketplaceAdmin))
            ->post('/admin/inventory/'.$offer->public_id.'/adjust', ['change' => -2])
            ->assertSessionHasErrors('reason');
    }

    #[Test]
    public function a_platform_adjustment_is_recorded_against_the_admin_and_visible_to_the_seller(): void
    {
        ['seller' => $seller, 'user' => $sellerUser] = $this->makeSeller();
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5, $seller);

        $admin = $this->makeAdmin(AdminRole::MarketplaceAdmin);

        $this->asAdmin($admin)
            ->post('/admin/inventory/'.$offer->public_id.'/adjust', [
                'change' => -2,
                'reason' => 'Counterfeit units removed after a trading-standards notice.',
            ])
            ->assertRedirect();

        $this->assertSame(3, $balance->refresh()->on_hand);

        $movement = InventoryMovement::query()
            ->where('reason', InventoryMovementReason::AdminAdjustment->value)
            ->firstOrFail();

        $this->assertSame('admin', $movement->actor_type);
        $this->assertSame($admin->id, $movement->actor_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.adjusted',
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
        ]);

        // The seller can see that the marketplace changed their count.
        $this->asUser($sellerUser)
            ->get('/seller/inventory/'.$offer->public_id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('movements.0.actorType', 'admin'));
    }

    #[Test]
    public function the_platform_sees_every_sellers_stock_in_one_place(): void
    {
        $this->stockedOffer(5, SellerAccount::factory()->create());
        $this->stockedOffer(9, SellerAccount::factory()->create());

        $this->asAdmin($this->makeAdmin(AdminRole::MarketplaceAdmin))
            ->get('/admin/inventory')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Index')->has('rows.data', 2));
    }
}
