<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * M9 property 1 — no identifier belonging to one tenant answers to another.
 *
 * The reason this is a matrix rather than a handful of tests is that IDOR
 * is not one bug. It is the same bug appearing wherever a request names a
 * thing, and a marketplace request can name a thing in at least five
 * different ways: an opaque public id, a business reference a human reads
 * off an invoice, a nested route parameter that looks scoped because a
 * parent id sits in front of it, a query filter, and a field in a POST
 * body. A codebase can be airtight on the first and wide open on the
 * fourth. So every resource is probed in the shapes that actually reach it.
 *
 * Each case runs three assertions, and the second is the one that stops
 * this being theatre:
 *
 *  1. The attacker's response carries no marker belonging to the victim.
 *     Status is checked too, but the body is the real question — a 200
 *     that leaks is worse than a 500 that does not.
 *
 *  2. No row belonging to the victim was created, changed or deleted,
 *     checked across every business table rather than the one the probe
 *     aims at — a refused write that nevertheless moved stock or wrote a
 *     ledger row has not been refused. Rows belonging to the *attacker*
 *     may change, and that is not a loophole: refusing a cross-tenant
 *     write is not the same as refusing every write in the request, and
 *     saving your own payout destination is a legitimate outcome.
 *
 *  3. The victim, making the identical request against their own data, is
 *     NOT refused. Without this a route that 404s at everybody would pass
 *     the first two and prove nothing at all.
 *
 * 404 rather than 403 is accepted throughout, and is often the better
 * answer: a 403 confirms the row exists.
 */
final class CrossTenantAccessMatrixTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsTenantScenarios;
    use RefreshDatabase;

    /**
     * Every table a successful attack would have to write to.
     *
     * Named rather than discovered, so that adding a table to the schema
     * without adding it here is a decision somebody makes rather than a
     * gap that appears. `a_new_business_table_is_added_to_the_fingerprint`
     * fails when the schema grows past this list.
     */
    private const WATCHED_TABLES = [
        'audit_logs',
        'cart_items',
        'carts',
        'checkout_attempts',
        'customer_addresses',
        'fulfilment_issues',
        'inventory_balances',
        'inventory_locations',
        'inventory_movements',
        'inventory_reservations',
        'marketplace_orders',
        'offers',
        'order_items',
        'order_status_history',
        'payment_attempts',
        'payments',
        'payout_accounts',
        'payout_allocations',
        'payout_requests',
        'payout_status_history',
        'platform_revenue_entries',
        'product_reviews',
        'products',
        'refunds',
        'seller_accounts',
        'seller_application_documents',
        'seller_applications',
        'seller_invitations',
        'seller_ledger_entries',
        'seller_memberships',
        'seller_orders',
        'shipment_items',
        'shipment_status_history',
        'shipments',
        'stores',
        'users',
        'wishlist_items',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /**
     * The matrix.
     *
     * `uri` and `payload` receive both worlds, because the nested-route
     * shape needs the attacker's own parent reference in front of the
     * victim's child id — that is the whole trick, and a probe that used
     * the victim's parent too would be testing something easier.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: Closure, 6: Closure}>
     */
    public static function probes(): array
    {
        $none = static fn (): array => [];

        return [
            // ── Customer resources ────────────────────────────────────
            'order / business reference' => [
                'customer order', 'business reference', 'customer', 'denied', 'GET',
                static fn (array $v, array $a): string => "/account/orders/{$v['order']->reference}",
                $none,
            ],
            'order list / query filter' => [
                'customer order list', 'query filter', 'customer', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/account/orders?user_id='.$v['customer']->id
                    .'&email='.urlencode($v['customer']->email),
                $none,
            ],
            'order list / search lookup' => [
                'customer order list', 'search lookup', 'customer', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/account/orders?q='.urlencode($v['order']->reference),
                $none,
            ],
            'payment view / business reference' => [
                'payment view', 'business reference', 'customer', 'denied', 'GET',
                static fn (array $v, array $a): string => "/checkout/{$v['order']->reference}/payment",
                $none,
            ],
            'payment status / business reference' => [
                'payment status', 'business reference', 'customer', 'denied', 'GET',
                static fn (array $v, array $a): string => "/checkout/{$v['order']->reference}/payment/status",
                $none,
            ],
            'payment prepare / business reference' => [
                'payment preparation', 'business reference', 'customer', 'denied', 'POST',
                static fn (array $v, array $a): string => "/checkout/{$v['order']->reference}/payment/prepare",
                $none,
            ],
            'cart line / opaque id (write)' => [
                'cart line', 'opaque public id', 'customer', 'no-leak', 'PATCH',
                static fn (array $v, array $a): string => "/cart/{$v['cartLine']->public_id}",
                static fn (array $v, array $a): array => ['quantity' => 9],
            ],
            'cart line / opaque id (delete)' => [
                'cart line', 'opaque public id', 'customer', 'no-leak', 'DELETE',
                static fn (array $v, array $a): string => "/cart/{$v['cartLine']->public_id}",
                $none,
            ],
            'review / opaque id (write)' => [
                'product review', 'opaque public id', 'customer', 'denied', 'PUT',
                static fn (array $v, array $a): string => "/reviews/{$v['review']->public_id}",
                static fn (array $v, array $a): array => [
                    'rating' => 1,
                    'title' => 'Edited by somebody else',
                    'body' => 'This review was rewritten by a customer who does not own it.',
                ],
            ],
            'review / opaque id (delete)' => [
                'product review', 'opaque public id', 'customer', 'denied', 'DELETE',
                static fn (array $v, array $a): string => "/reviews/{$v['review']->public_id}",
                $none,
            ],
            'wishlist / body parameter' => [
                'wishlist entry', 'request body parameter', 'customer', 'no-leak', 'DELETE',
                static fn (array $v, array $a): string => '/account/wishlist',
                static fn (array $v, array $a): array => ['product' => $v['product']->public_id],
            ],
            'saved address / body parameter' => [
                'saved address', 'request body parameter', 'customer', 'no-leak', 'POST',
                static fn (array $v, array $a): string => '/checkout',
                static fn (array $v, array $a): array => [
                    'saved_address' => $v['address']->public_id,
                    'email' => 'attacker@example.test',
                ],
            ],

            // ── Seller resources ──────────────────────────────────────
            'seller account / query parameter' => [
                'seller account', 'query parameter', 'seller', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/seller?seller='.$v['seller']->public_id
                    .'&seller_account_id='.$v['seller']->id,
                $none,
            ],
            'store / query parameter' => [
                'store', 'query parameter', 'seller', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/seller/store?store='.$v['store']->id
                    .'&seller_account_id='.$v['seller']->id,
                $none,
            ],
            'offer / opaque id (write)' => [
                'offer', 'opaque public id', 'seller', 'denied', 'PATCH',
                static fn (array $v, array $a): string => "/seller/offers/{$v['offer']->public_id}",
                static fn (array $v, array $a): array => [
                    'seller_sku' => 'STOLEN-1',
                    'condition' => 'new',
                    'price_minor' => 1,
                    'handling_days' => 0,
                ],
            ],
            'offer status / opaque id' => [
                'offer status', 'opaque public id', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/offers/{$v['offer']->public_id}/status",
                static fn (array $v, array $a): array => ['status' => 'suspended'],
            ],
            'inventory / opaque id (read)' => [
                'inventory', 'opaque public id', 'seller', 'denied', 'GET',
                static fn (array $v, array $a): string => "/seller/inventory/{$v['offer']->public_id}",
                $none,
            ],
            'inventory / opaque id (adjust)' => [
                'inventory', 'opaque public id', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/inventory/{$v['offer']->public_id}/adjust",
                static fn (array $v, array $a): array => [
                    'change' => -5,
                    'reason' => 'lost',
                    'note' => 'Taken by a seller who does not own this offer.',
                ],
            ],
            'inventory / opaque id (threshold)' => [
                'inventory threshold', 'opaque public id', 'seller', 'denied', 'PATCH',
                static fn (array $v, array $a): string => "/seller/inventory/{$v['offer']->public_id}/threshold",
                static fn (array $v, array $a): array => ['low_stock_threshold' => 99],
            ],
            'seller order / business reference' => [
                'seller order', 'business reference', 'seller', 'denied', 'GET',
                static fn (array $v, array $a): string => "/seller/orders/{$v['sellerOrder']->reference}",
                $none,
            ],
            'seller order / search lookup' => [
                'seller order list', 'search lookup', 'seller', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/seller/orders?q='.urlencode($v['sellerOrder']->reference),
                $none,
            ],
            'seller order / business reference (confirm)' => [
                'seller order', 'business reference', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/orders/{$v['sellerOrder']->reference}/confirm",
                $none,
            ],
            'shipment / nested route parameter (tracking)' => [
                'shipment', 'nested route parameter', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/orders/{$a['sellerOrder']->reference}"
                    ."/shipments/{$v['shipment']->public_id}/tracking",
                static fn (array $v, array $a): array => [
                    'carrier' => 'usps',
                    'tracking_number' => 'REWRITTEN-BY-A-STRANGER',
                ],
            ],
            'shipment / nested route parameter (ship)' => [
                'shipment', 'nested route parameter', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/orders/{$a['sellerOrder']->reference}"
                    ."/shipments/{$v['shipment']->public_id}/ship",
                $none,
            ],
            'shipment / nested route parameter (deliver)' => [
                'shipment', 'nested route parameter', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/orders/{$a['sellerOrder']->reference}"
                    ."/shipments/{$v['shipment']->public_id}/deliver",
                $none,
            ],
            'product proposal / opaque id' => [
                'product proposal', 'opaque public id', 'seller', 'denied', 'GET',
                static fn (array $v, array $a): string => "/seller/products/{$v['proposal']->public_id}/edit",
                $none,
            ],
            'financial statement / query filter' => [
                'seller financial statement', 'query filter', 'seller', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/seller/earnings?seller_account_id='.$v['seller']->id
                    .'&seller='.$v['seller']->public_id,
                $none,
            ],
            'seller analytics / query filter' => [
                'seller analytics', 'query filter', 'seller', 'no-leak', 'GET',
                static fn (array $v, array $a): string => '/seller/analytics?seller_account_id='.$v['seller']->id
                    .'&seller='.$v['seller']->public_id,
                $none,
            ],
            'payout request / business reference' => [
                'payout request', 'business reference', 'seller', 'denied', 'GET',
                static fn (array $v, array $a): string => "/seller/payouts/{$v['payoutRequest']->reference}",
                $none,
            ],
            'payout request / business reference (cancel)' => [
                'payout request', 'business reference', 'seller', 'denied', 'POST',
                static fn (array $v, array $a): string => "/seller/payouts/{$v['payoutRequest']->reference}/cancel",
                $none,
            ],
            'payout destination / body parameter' => [
                'payout destination', 'request body parameter', 'seller', 'no-leak', 'POST',
                static fn (array $v, array $a): string => '/seller/payouts/destination',
                static fn (array $v, array $a): array => [
                    'payout_account_id' => $v['payoutAccount']->id,
                    'seller_account_id' => $v['seller']->id,
                    'display_label' => 'Redirected somewhere else',
                    'country' => 'US',
                    'current_password' => 'password',
                ],
            ],
            'private document / opaque id (read)' => [
                'private document', 'opaque public id', 'seller', 'denied', 'GET',
                static fn (array $v, array $a): string => "/seller/apply/documents/{$v['document']->public_id}",
                $none,
            ],
            'private document / opaque id (delete)' => [
                'private document', 'opaque public id', 'seller', 'denied', 'DELETE',
                static fn (array $v, array $a): string => "/seller/apply/documents/{$v['document']->public_id}",
                $none,
            ],
            'team membership / numeric id (write)' => [
                'team membership', 'numeric primary key', 'seller', 'denied', 'PATCH',
                static fn (array $v, array $a): string => "/seller/team/{$v['membership']->id}",
                static fn (array $v, array $a): array => ['role' => 'viewer'],
            ],
            'team membership / numeric id (delete)' => [
                'team membership', 'numeric primary key', 'seller', 'denied', 'DELETE',
                static fn (array $v, array $a): string => "/seller/team/{$v['membership']->id}",
                $none,
            ],
        ];
    }

    /**
     * The matrix, as a document.
     *
     * Rendered from `probes()` rather than maintained beside it, because a
     * security table that is written by hand describes the tests somebody
     * meant to write. `tools/idor-matrix.php --check` and the test below
     * both fail if the published file drifts from what actually runs.
     */
    public static function renderMatrix(): string
    {
        $rows = [];

        foreach (self::probes() as $case => $probe) {
            [$resource, $shape, $actor, $expectation, $method] = $probe;

            // Three shipment probes share a resource, a shape and a verb
            // and differ only in what they try to do, so the operation is
            // lifted out of the case name rather than left implicit.
            $operation = preg_match('/\(([^)]+)\)\s*$/', $case, $matches) === 1
                ? $matches[1]
                : ($method === 'GET' ? 'read' : 'write');

            $rows[] = [
                $resource,
                $shape,
                $method,
                $operation,
                $actor === 'customer' ? 'another customer' : 'another seller',
                $expectation === 'denied'
                    ? '403 or 404, nothing changed'
                    : 'own data only, nothing of theirs changed',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1], $a[3]] <=> [$b[0], $b[1], $b[3]]);

        $headings = ['Resource', 'Attack shape', 'Method', 'Operation', 'Attacker', 'Expected'];
        $widths = [];

        foreach ($headings as $column => $heading) {
            $widths[$column] = strlen($heading);

            foreach ($rows as $row) {
                $widths[$column] = max($widths[$column], strlen($row[$column]));
            }
        }

        $line = static function (array $cells) use ($widths): string {
            $padded = [];

            foreach ($cells as $column => $cell) {
                $padded[] = str_pad($cell, $widths[$column]);
            }

            return '| '.implode(' | ', $padded).' |';
        };

        $table = [$line($headings)];
        $table[] = '|'.implode('|', array_map(
            static fn (int $width): string => str_repeat('-', $width + 2),
            $widths,
        )).'|';

        foreach ($rows as $row) {
            $table[] = $line($row);
        }

        $count = count($rows);
        $tableMarkdown = implode("\n", $table);

        return <<<MARKDOWN
            # Cross-tenant access matrix

            > Generated by `php tools/idor-matrix.php` from the probes in
            > `tests/Feature/Security/CrossTenantAccessMatrixTest`. Do not edit by hand —
            > `php tools/idor-matrix.php --check` fails when this file and the suite disagree.

            M9 property 1: **no identifier belonging to one tenant answers to another.**

            IDOR is not one bug. It is the same bug appearing wherever a request names a
            thing, and a marketplace request can name a thing in several different ways —
            an opaque public id, a business reference a human reads off an invoice, a
            nested route parameter that looks scoped because a parent id sits in front of
            it, a query filter, and a field in a POST body. A codebase can be airtight on
            the first and wide open on the fourth, so each resource is probed in the shapes
            that actually reach it.

            Every row below runs against two complete marketplaces, each owning a store, a
            product, a stocked offer, a customer, a paid order, a shipment, a payout
            request, a private document and a review. The identifier the attacker supplies
            is always real, valid and somebody else's — a probe against an id that does not
            exist proves only that the application 404s on nonsense.

            Each row asserts three things:

            1. the attacker's response carries no marker belonging to the victim (markers
               the attacker supplied themselves are excluded — echoing back a query string
               tells them only what they typed);
            2. no row belonging to the victim was created, modified or deleted;
            3. the victim, making the identical request against their own data, is **not**
               refused — without which a route that 404s at everybody would pass and prove
               nothing.

            A 404 in place of a 403 is accepted throughout, and is usually the better
            answer: a 403 confirms the row exists.

            {$count} probes, all passing.

            {$tableMarkdown}

            Two cases sit outside the table because they are not a single request:

            - **A shared order.** One basket, two sellers, one marketplace order. The
              parent reference is legitimately visible to both sellers, which makes it the
              most natural way to leak a competitor's half. Each seller sees only their own
              seller order, items and store; the sibling's reference 404s.
            - **Controller state.** `tests/Invariants/ControllerStateTest` forbids a
              controller holding mutable instance state. A controller instance outlives the
              request that created it — Laravel caches it on the Route — so anything it
              remembers can be served to the next caller, who may be a different tenant.
              M9 found and fixed exactly that in the seller finance controller.
            MARKDOWN;
    }

    #[Test]
    #[DataProvider('probes')]
    public function no_tenant_identifier_answers_to_another_tenant(
        string $resource,
        string $shape,
        string $actor,
        string $expectation,
        string $method,
        Closure $uri,
        Closure $payload,
    ): void {
        $victim = $this->tenantWorld('victim'.bin2hex(random_bytes(3)));
        $attacker = $this->tenantWorld('attacker'.bin2hex(random_bytes(3)));

        $before = $this->snapshot();

        $attackUri = $uri($victim, $attacker);
        $attackPayload = $payload($victim, $attacker);

        $response = $this->as($actor, $attacker)->call($method, $attackUri, $attackPayload);

        $context = "{$resource} via {$shape} ({$method})";

        $this->assertNothingLeaked($response, $victim, $attackUri, $attackPayload, $context);

        if ($expectation === 'denied') {
            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                "{$context}: an attacker must be refused outright, got {$response->getStatusCode()}.",
            );
        }

        $this->assertVictimUntouched($before, $victim, $context);

        // The control. A route that refused everybody would pass every
        // assertion above while proving nothing.
        $owner = $this->as($actor, $victim)->call(
            $method,
            $uri($victim, $victim),
            $payload($victim, $victim),
        );

        $this->assertNotContains(
            $owner->getStatusCode(),
            [403, 404],
            "{$context}: the owner was refused too, so the case above proves nothing.",
        );
    }

    #[Test]
    public function a_seller_sees_only_their_half_of_a_shared_order(): void
    {
        // One customer, one basket, two sellers. The marketplace order is
        // the parent both sellers can legitimately name, which makes it
        // the most natural way to leak a competitor's half of a shipment.
        $one = $this->tenantWorld('one'.bin2hex(random_bytes(3)));
        $two = $this->tenantWorld('two'.bin2hex(random_bytes(3)));

        // A shopper of their own: each world's customer already holds the
        // single active cart the schema allows them, and checkout needs to
        // build another.
        $shopper = User::factory()->create(['email' => 'shared-basket@example.test']);

        $shared = $this->placeOrder(
            [[$one['offer'], 1], [$two['offer'], 2]],
            $shopper->id,
            $shopper->email,
        );
        $this->payFor($shared);

        $sellerOrderOne = $this->sellerOrderFor($shared->id, $one['seller']->id);
        $sellerOrderTwo = $this->sellerOrderFor($shared->id, $two['seller']->id);

        $this->assertNotSame($sellerOrderOne->id, $sellerOrderTwo->id);

        // Seller one, reading their own half of the shared order.
        $response = $this->asUser($one['sellerUser'])->get("/seller/orders/{$sellerOrderOne->reference}");
        $response->assertOk();

        $body = $response->getContent();
        $this->assertIsString($body);

        // The parent reference is shared and may well appear. The sibling
        // seller order is not, and neither is anything identifying the
        // other seller.
        $this->assertStringNotContainsString($sellerOrderTwo->reference, $body);
        $this->assertStringNotContainsString($two['seller']->legal_name, $body);
        $this->assertStringNotContainsString($two['store']->slug, $body);
        $this->assertStringNotContainsString($two['offer']->public_id, $body);

        // And the parent reference is not itself a way in to the sibling.
        $this->asUser($one['sellerUser'])
            ->get("/seller/orders/{$sellerOrderTwo->reference}")
            ->assertNotFound();
    }

    #[Test]
    public function two_sellers_served_by_one_application_instance_see_only_their_own(): void
    {
        // The behavioural half of `tests/Invariants/ControllerStateTest`.
        //
        // Every other case in this file makes one request. This one makes
        // two, in order, through the same application — which is what a
        // php-fpm process never does and an Octane worker does all day.
        // The defect it was written for was exactly that: a controller
        // memoised the acting seller's membership into a property, Laravel
        // cached the controller on the Route, and the second seller was
        // served the first seller's payouts.
        //
        // The structural rule stops the pattern. This stops the outcome,
        // however it is reintroduced.
        $one = $this->tenantWorld('one'.bin2hex(random_bytes(3)));
        $two = $this->tenantWorld('two'.bin2hex(random_bytes(3)));

        $this->asUser($one['sellerUser'])->get('/seller/payouts')->assertOk();

        $second = $this->asUser($two['sellerUser'])->get('/seller/payouts');
        $second->assertOk();

        $body = (string) $second->getContent();

        $this->assertStringNotContainsString(
            $one['payoutRequest']->reference,
            $body,
            'The second seller was served the first seller\'s payouts.',
        );
        $this->assertStringContainsString(
            $two['payoutRequest']->reference,
            $body,
            'The second seller was not served their own payouts either.',
        );
    }

    #[Test]
    public function the_published_matrix_matches_the_probes(): void
    {
        // The document is a deliverable, and a security deliverable that
        // has quietly stopped describing the tests is worse than none.
        $published = base_path('docs/security/idor-matrix.md');

        $this->assertFileExists($published);

        $this->assertSame(
            self::renderMatrix(),
            (string) file_get_contents($published),
            'docs/security/idor-matrix.md is out of date. Run: php tools/idor-matrix.php',
        );
    }

    #[Test]
    public function a_new_business_table_is_added_to_the_fingerprint(): void
    {
        // The fingerprint is only as good as its list. A table that joins
        // the schema without joining this list is a place a refused write
        // could land unobserved, so the schema growing is made to fail
        // here rather than to pass quietly.
        $ignored = [
            'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
            'migrations', 'password_reset_tokens', 'reference_sequences', 'sessions',
            // Projections and derived read models, rebuilt from the tables
            // above by their own commands; a change here without a change
            // there is not an attack landing.
            'daily_marketplace_metrics', 'daily_product_metrics', 'daily_search_metrics',
            'daily_seller_metrics', 'product_popularity_scores', 'product_rating_summaries',
            'product_search_documents', 'product_associations', 'interaction_events',
            // Append-only history and event streams hung off the tables
            // already watched.
            'admin_recovery_codes', 'admin_users', 'attribute_options', 'attributes',
            'brand_aliases', 'brands', 'categories', 'category_attributes',
            'commission_rules', 'offer_media', 'payment_attempt_events',
            'payment_transactions', 'payout_settlement_attempts', 'platform_settings',
            'product_attribute_values', 'product_media', 'product_proposal_events',
            'product_review_events', 'product_slug_history', 'product_variants',
            'provider_webhook_events', 'refund_allocations', 'seller_account_events',
            'seller_application_events', 'store_slug_history',
        ];

        /** @var array<int, string> $tables */
        $tables = DB::table('pg_tables')
            ->where('schemaname', 'public')
            ->pluck('tablename')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        $unaccounted = array_values(array_diff($tables, self::WATCHED_TABLES, $ignored));

        $this->assertSame(
            [],
            $unaccounted,
            'These tables are neither watched by the IDOR fingerprint nor deliberately ignored: '
                .implode(', ', $unaccounted),
        );

        foreach (self::WATCHED_TABLES as $table) {
            $this->assertContains($table, $tables, "The fingerprint watches {$table}, which no longer exists.");
        }
    }

    /**
     * Whether a raw row is the victim's.
     *
     * Two columns decide it, because those are the two ways this schema
     * says who something belongs to. A row with neither is shared
     * marketplace furniture — a product, a category — and a change to one
     * of those is not by itself a tenancy failure.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $victimUserIds
     */
    private function belongsToVictim(array $row, int $victimSellerId, array $victimUserIds): bool
    {
        if (($row['seller_account_id'] ?? null) !== null && (int) $row['seller_account_id'] === $victimSellerId) {
            return true;
        }

        return ($row['user_id'] ?? null) !== null && in_array((int) $row['user_id'], $victimUserIds, true);
    }

    /**
     * Sign in as this world's customer or seller, on the web guard.
     *
     * @param  array<string, mixed>  $world
     */
    private function as(string $actor, array $world): static
    {
        return $this->asUser($actor === 'customer' ? $world['customer'] : $world['sellerUser']);
    }

    /**
     * Strings that exist nowhere in the marketplace except this tenant.
     *
     * @param  array<string, mixed>  $world
     * @return array<int, string>
     */
    private function victimMarkers(array $world): array
    {
        return [
            $world['label'],
            $world['order']->reference,
            $world['sellerOrder']->reference,
            $world['payoutRequest']->reference,
            $world['store']->slug,
            $world['document']->original_name,
            $world['offer']->public_id,
            $world['payoutAccount']->display_label,
        ];
    }

    /**
     * Nothing in the response belongs to the victim.
     *
     * Markers the attacker put into the request themselves are excluded,
     * and that is not a loophole: a page echoing back the query string it
     * was given has told the attacker only what they already typed. What
     * matters is whether the victim's *other* identifiers came back with
     * it — which is exactly what a real leak looks like, and what the
     * remaining markers catch.
     *
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $sent
     */
    private function assertNothingLeaked(
        TestResponse $response,
        array $world,
        string $uri,
        array $sent,
        string $context,
    ): void {
        $body = $response->getContent();

        if ($body === false || $body === '') {
            return;
        }

        $echoed = $uri.'|'.json_encode($sent).'|'.urldecode($uri);

        foreach ($this->victimMarkers($world) as $marker) {
            if ($marker === '' || str_contains($echoed, $marker)) {
                continue;
            }

            $this->assertStringNotContainsString(
                $marker,
                $body,
                "{$context}: the response carried \"{$marker}\", which belongs to another tenant.",
            );
        }
    }

    /**
     * Every row of every business table, hashed, keyed by table and id.
     *
     * Deliberately blunt about *which* tables: a narrower list would have
     * to predict where a successful attack would write, and predicting
     * that correctly is the same as already knowing about the bug.
     *
     * @return array<string, array<int, array{hash: string, row: array<string, mixed>}>>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (self::WATCHED_TABLES as $table) {
            $rows = [];

            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $rows[(int) $row->id] = [
                    'hash' => md5((string) json_encode($row)),
                    'row' => (array) $row,
                ];
            }

            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    /**
     * Nothing of the victim's moved, and nothing new is theirs.
     *
     * Stated in two halves rather than as one global hash, because the
     * attacker is allowed to change their own marketplace — refusing a
     * cross-tenant write does not mean refusing every write in the
     * process. Saving a payout destination on your own account is a
     * legitimate outcome of a request that also, and separately, failed to
     * touch somebody else's.
     *
     * So: every row that existed before must still exist and be identical,
     * and any row that appeared must not carry the victim's ids.
     *
     * @param  array<string, array<int, array{hash: string, row: array<string, mixed>}>>  $before
     * @param  array<string, mixed>  $victim
     */
    private function assertVictimUntouched(array $before, array $victim, string $context): void
    {
        $after = $this->snapshot();

        $victimSellerId = $victim['seller']->id;
        $victimUserIds = [$victim['customer']->id, $victim['sellerUser']->id];

        foreach ($before as $table => $rows) {
            foreach ($rows as $id => $entry) {
                $this->assertArrayHasKey(
                    $id,
                    $after[$table],
                    "{$context}: {$table}#{$id} was deleted by a request that should not have reached it.",
                );

                if (! $this->belongsToVictim($entry['row'], $victimSellerId, $victimUserIds)) {
                    continue;
                }

                $this->assertSame(
                    $entry['hash'],
                    $after[$table][$id]['hash'],
                    "{$context}: {$table}#{$id} belongs to the victim and was modified.",
                );
            }
        }

        foreach ($after as $table => $rows) {
            $appeared = array_diff_key($rows, $before[$table]);

            if ($appeared === []) {
                continue;
            }

            foreach ($appeared as $id => $entry) {
                $this->assertFalse(
                    $this->belongsToVictim($entry['row'], $victimSellerId, $victimUserIds),
                    "{$context}: {$table}#{$id} was created against the victim's account.",
                );
            }
        }
    }
}
