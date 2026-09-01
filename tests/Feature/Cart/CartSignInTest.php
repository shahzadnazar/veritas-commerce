<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Listeners\AdoptCartOnLogin;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Queries\ResolveCart;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The anonymous cart, across the sign-in boundary.
 *
 * Bound to the framework's Login event rather than to the sign-in
 * controller, so these tests drive the real HTTP routes: the point is that
 * every path a customer can become authenticated through behaves the same,
 * and a test that called the listener directly would not prove it.
 */
final class CartSignInTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    private const TOKEN = 'veritas_cart_token';

    #[Test]
    public function a_basket_filled_before_signing_in_survives_signing_in(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = $this->customer();
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($anonymous, $offer->public_id, 2);

        $this->withSession([self::TOKEN => 'browser-a'])
            ->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect();

        $anonymous->refresh();

        $this->assertSame($user->id, $anonymous->user_id);
        $this->assertSame(CartStatus::Active, $anonymous->status);
        $this->assertSame(2, (int) CartItem::query()->where('cart_id', $anonymous->id)->value('quantity'));
    }

    #[Test]
    public function the_two_carts_are_merged_when_the_customer_already_had_one(): void
    {
        ['offer' => $kettle] = $this->sellableOffer('Kettle');
        ['offer' => $lamp] = $this->sellableOffer('Lamp');

        $user = $this->customer();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $kettle->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $lamp->public_id, 1);

        $this->withSession([self::TOKEN => 'browser-a'])
            ->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect();

        $this->assertSame(2, CartItem::query()->where('cart_id', $mine->id)->count());
        $this->assertSame(CartStatus::Merged, $anonymous->refresh()->status);
    }

    #[Test]
    public function the_browser_token_is_dropped_once_there_is_an_account_behind_it(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = $this->customer();
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);

        $this->withSession([self::TOKEN => 'browser-a'])
            ->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        // Otherwise signing out would leave the browser holding a handle
        // on the account's cart.
        $this->assertNull(session(self::TOKEN));
    }

    #[Test]
    public function registering_carries_the_basket_across_too(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($anonymous, $offer->public_id, 3);

        $this->withSession([self::TOKEN => 'browser-a'])->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'terms' => true,
        ]);

        $user = User::query()->where('email', 'ada@example.test')->firstOrFail();
        $anonymous->refresh();

        // The listener is on the Login event, so there is no second
        // sign-in path to remember to wire this into.
        $this->assertSame($user->id, $anonymous->user_id);
        $this->assertSame(3, (int) CartItem::query()->where('cart_id', $anonymous->id)->value('quantity'));
    }

    #[Test]
    public function a_failed_sign_in_leaves_the_anonymous_cart_alone(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = $this->customer();
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);

        $this->withSession([self::TOKEN => 'browser-a'])
            ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $anonymous->refresh();

        $this->assertNull($anonymous->user_id);
        $this->assertSame('browser-a', $anonymous->session_token);
        $this->assertSame(CartStatus::Active, $anonymous->status);
    }

    #[Test]
    public function signing_in_with_no_anonymous_cart_does_nothing_at_all(): void
    {
        $user = $this->customer();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect();

        $this->assertDatabaseCount('carts', 0);
    }

    #[Test]
    public function an_admin_signing_in_never_touches_a_storefront_cart(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $anonymous = $this->cart(sessionToken: 'browser-a');
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);

        // The two realms are separate guards, sessions and cookies; the
        // listener is scoped to `web` so the admin realm cannot adopt a
        // shopper's basket.
        $listener = app(AdoptCartOnLogin::class);
        $listener->handle(new Login('admin', $this->customer(), false));

        $anonymous->refresh();

        $this->assertNull($anonymous->user_id);
        $this->assertSame(CartStatus::Active, $anonymous->status);
    }

    #[Test]
    public function an_authenticated_customer_resolves_their_own_cart_not_the_browsers(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = $this->customer();

        $mine = $this->cart(userId: $user->id);
        $someoneElse = $this->cart(sessionToken: 'browser-a');
        app(AddOfferToCart::class)($someoneElse, $offer->public_id, 1);

        $request = Request::create('/cart');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(self::TOKEN, 'browser-a');
        $request->setUserResolver(static fn (): User => $user);

        $resolved = app(ResolveCart::class)->existing($request);

        // §4: ownership comes from the authenticated identity first. A
        // stale token in the session cannot redirect it.
        $this->assertSame($mine->id, $resolved?->id);
    }

    #[Test]
    public function resolving_twice_returns_the_same_cart_rather_than_a_second_one(): void
    {
        $request = Request::create('/cart');
        $request->setLaravelSession(app('session.store'));

        $first = app(ResolveCart::class)->orCreate($request);
        $second = app(ResolveCart::class)->orCreate($request);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Cart::query()->count());
    }

    private function customer(): User
    {
        return User::factory()->create([
            'password' => Hash::make('secret-password'),
            'email_verified_at' => now(),
        ]);
    }
}
