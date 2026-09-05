<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Enums\DocumentKind;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use App\Modules\Sellers\Queries\ResolveDocumentDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Tests\Support\RecordingObjectStore;
use Tests\TestCase;

/**
 * M9 property 4 — a seller's private paperwork reaches nobody else.
 *
 * THE TRUST BOUNDARY, established from the code rather than assumed:
 *
 * There is one class of private seller document — the verification
 * paperwork attached to a seller application (`seller_application_documents`,
 * DocumentKind: registration certificates, identity, address, banking).
 * It is stored on the `documents` disk, which is configured private, is
 * marked `serve => false`, and has no public URL of any kind.
 *
 * OWNER            the applying USER, not a seller account. Applications
 *                  exist before a seller account does, and the seller
 *                  route resolves the application from the signed-in user
 *                  — never from an id in the request.
 * SELLER ACTORS    the applicant alone. Seller membership is NOT a grant:
 *                  an owner of an approved store cannot read the paperwork
 *                  of an application they did not submit.
 * PLATFORM ACTORS  seller.view_sensitive only — super_admin and
 *                  seller_operations. Marketplace admin, catalogue
 *                  moderator, finance, support and analyst are refused.
 * UNAUTHORISED     everybody else, including a customer who has bought
 *                  from the seller and a guest.
 *
 * THE DELIVERY MODEL is both, chosen by the disk:
 *
 *   - a remote disk that can sign returns a redirect to a short-lived URL
 *     (`veritas.storage.signed_url_seconds`, 120 by default);
 *   - a local disk cannot sign, so the bytes are streamed by the
 *     application under the same authorisation.
 *
 * The signed URL is deliberately a bearer capability for its lifetime.
 * That is not a defect and is not tested as one. What is tested is that
 * an unauthorised actor can never MINT one, never discover one, and that
 * the capability is short-lived, tamper-evident and never leaked into a
 * response, a prop or a log.
 *
 * As with properties 1-3, every denial asserts two things:
 *
 *     ACCESS IS DENIED
 *     AND NO PRIVATE CAPABILITY LEAKED.
 *
 * A 404 whose body carries the storage key, the signed URL or the
 * original filename has denied nothing worth denying.
 */
final class PrivateDocumentIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One applicant with one real private document.
     *
     * The bytes go through the real ObjectStore on the private disk, so
     * the object being protected is an object that actually exists —
     * a test against a row with no file behind it proves less.
     *
     * @return array{user: User, application: SellerApplication, document: SellerApplicationDocument, key: string}
     */
    private function applicantWithDocument(string $label, SellerApplicationStatus $status = SellerApplicationStatus::UnderReview): array
    {
        $user = User::factory()->create(['email' => "applicant-{$label}@example.test"]);

        $application = SellerApplication::factory()->create([
            'user_id' => $user->id,
            'status' => $status->value,
            'legal_name' => "{$label} Holdings Ltd",
        ]);

        $stored = app(ObjectStore::class)->putContents(
            "the private contents of {$label}",
            'seller-documents',
            'application/pdf',
            Visibility::Private,
        );

        $document = SellerApplicationDocument::factory()->create([
            'seller_application_id' => $application->id,
            'kind' => DocumentKind::cases()[0]->value,
            'disk' => $stored->disk,
            'path' => $stored->key,
            'mime' => 'application/pdf',
            'bytes' => $stored->bytes,
            'original_name' => "{$label}-passport.pdf",
        ]);

        return [
            'user' => $user,
            'application' => $application,
            'document' => $document,
            'key' => $stored->key,
        ];
    }

    /**
     * Denied, and nothing private came back with the denial.
     *
     * @param  array{document: SellerApplicationDocument, key: string}  $victim
     */
    private function assertDeniedWithoutLeak(TestResponse $response, array $victim, string $attack): void
    {
        $status = $response->getStatusCode();
        $location = $response->baseResponse->headers->get('Location');

        /*
         * 403 and 404 are the two refusals policy uses, and 404 is
         * generally the better one — a 403 confirms the document exists.
         *
         * A 302 counts as a refusal only when it is the application
         * sending an unauthenticated caller to a sign-in page. The whole
         * property is that an unauthorised actor never receives the
         * capability, so a redirect that goes anywhere near storage is the
         * failure itself rather than a variant of denial.
         */
        if ($status === 302) {
            $this->assertNotNull($location, "{$attack}: a 302 with nowhere to go.");
            $this->assertMatchesRegularExpression(
                '#^https?://[^/]+/(admin/)?login#',
                (string) $location,
                "{$attack}: refused with a redirect that was not a sign-in page.",
            );
        } else {
            $this->assertContains(
                $status,
                [403, 404],
                "{$attack}: expected a refusal, got {$status}.",
            );

            $this->assertNull($location, "{$attack}: the refusal handed out a location.");
        }

        $body = (string) $response->getContent();

        foreach ([
            'the storage key' => $victim['key'],
            'the original filename' => (string) $victim['document']->original_name,
            'the document id' => (string) $victim['document']->public_id,
        ] as $what => $marker) {
            if ($marker === '') {
                continue;
            }

            $this->assertStringNotContainsString(
                $marker,
                $body,
                "{$attack}: the refusal leaked {$what}.",
            );
        }

        // Nor the contents, nor a signed link to them.
        $this->assertStringNotContainsString('the private contents of', $body, "{$attack}: document bytes leaked.");
        $this->assertStringNotContainsString('X-Amz-Signature', $body, "{$attack}: a signed URL leaked.");
        $this->assertStringNotContainsString('signature=', $body, "{$attack}: a signed URL leaked.");

        // Whatever the shape of the refusal, no location it offers may
        // carry the capability or anything identifying the document.
        foreach ([$victim['key'], (string) $victim['document']->public_id, 'X-Amz-Signature'] as $marker) {
            if ($marker === '') {
                continue;
            }

            $this->assertStringNotContainsString(
                $marker,
                (string) $location,
                "{$attack}: the refusal's location carried \"{$marker}\".",
            );
        }
    }

    // ── 1, 9, 15 — another applicant, by every identifier ─────────────

    #[Test]
    public function one_applicant_cannot_read_anothers_paperwork_by_any_identifier(): void
    {
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');

        // Every id the attacker could learn or guess: the document's own
        // opaque id, its numeric row id, the application's, the victim's
        // user id, and the raw storage key. Opacity is not authorisation —
        // a valid foreign identifier must fail because it is foreign.
        foreach ([
            'document public id' => (string) $victim['document']->public_id,
            'document row id' => (string) $victim['document']->id,
            'application public id' => (string) $victim['application']->public_id,
            'application reference' => (string) $victim['application']->reference,
            'storage key' => $victim['key'],
        ] as $shape => $identifier) {
            $response = $this->actingAs($attacker['user'])
                ->get('/seller/apply/documents/'.rawurlencode($identifier));

            $this->assertDeniedWithoutLeak($response, $victim, "cross-applicant read by {$shape}");
        }
    }

    #[Test]
    public function the_owning_applicant_can_read_their_own(): void
    {
        // The anti-vacuity control. Without it, a route that refused
        // everybody would pass every test above and prove nothing.
        $owner = $this->applicantWithDocument('owner');

        $response = $this->actingAs($owner['user'])
            ->get("/seller/apply/documents/{$owner['document']->public_id}");

        $response->assertOk();
        $this->assertSame('the private contents of owner', $response->streamedContent());
    }

    // ── 5 — minting is the real prize ─────────────────────────────────

    #[Test]
    public function no_unauthorised_actor_can_make_the_application_mint_a_link(): void
    {
        // Whether the disk streams or signs, the request that hands out
        // the capability is this one. If an attacker cannot make it run
        // for a foreign document, there is no capability to steal.
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');
        $customer = User::factory()->create(['email' => 'shopper@example.test']);

        $actors = [
            'another applicant' => $attacker['user'],
            'a customer' => $customer,
        ];

        foreach ($actors as $who => $actor) {
            foreach ([
                "/seller/apply/documents/{$victim['document']->public_id}",
                "/admin/applications/documents/{$victim['document']->public_id}",
            ] as $route) {
                $response = $this->actingAs($actor)->get($route);

                $this->assertDeniedWithoutLeak($response, $victim, "{$who} minting via {$route}");
            }
        }

        // And a guest.
        foreach ([
            "/seller/apply/documents/{$victim['document']->public_id}",
            "/admin/applications/documents/{$victim['document']->public_id}",
        ] as $route) {
            $response = $this->get($route);

            $this->assertNotSame(200, $response->getStatusCode(), "A guest reached {$route}.");
            $this->assertStringNotContainsString('the private contents of', (string) $response->getContent());
        }
    }

    // ── 2, 5, 12, 18, 23 — the signing branch ─────────────────────────

    /** Swap in a store that can sign, the way a remote disk can. */
    private function signingStore(): RecordingObjectStore
    {
        $store = new RecordingObjectStore(app(ObjectStore::class), 'https://objects.example.test/private');

        $this->app->instance(ObjectStore::class, $store);

        return $store;
    }

    #[Test]
    public function a_signable_disk_hands_the_owner_a_short_lived_redirect(): void
    {
        // The other delivery branch. A local disk cannot sign, so without
        // a store that can, this path would only ever run against real
        // object storage — which is exactly where nobody tests it.
        $owner = $this->applicantWithDocument('owner');
        $store = $this->signingStore();

        $response = $this->actingAs($owner['user'])
            ->get("/seller/apply/documents/{$owner['document']->public_id}");

        $response->assertRedirect();

        $location = (string) $response->baseResponse->headers->get('Location');

        $this->assertStringStartsWith('https://objects.example.test/private/', $location);
        $this->assertStringContainsString('X-Amz-Signature=', $location);

        // The lifetime is the configured one, and it is short. A signed
        // link is a bearer capability for as long as it lives, so its
        // life is the blast radius.
        $minted = $store->minted();

        $this->assertCount(1, $minted);
        $this->assertSame((int) config('veritas.storage.signed_url_seconds'), $minted[0]['seconds']);
        $this->assertLessThanOrEqual(900, $minted[0]['seconds']);
    }

    #[Test]
    public function an_unauthorised_caller_never_causes_a_link_to_be_minted(): void
    {
        /*
         * The strongest form of the property, and the one a status code
         * cannot express.
         *
         * A 403 returned AFTER a signed URL was created has still written
         * that URL into a log line, a trace and an APM span — the
         * capability escaped even though the response did not carry it.
         * So this asserts the store was never asked at all.
         */
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');
        $customer = User::factory()->create(['email' => 'nobody@example.test']);

        $store = $this->signingStore();

        $attempts = [
            'another applicant' => [$attacker['user'], "/seller/apply/documents/{$victim['document']->public_id}"],
            'a customer' => [$customer, "/seller/apply/documents/{$victim['document']->public_id}"],
            'a customer at the admin route' => [$customer, "/admin/applications/documents/{$victim['document']->public_id}"],
        ];

        foreach ($attempts as $who => [$actor, $route]) {
            $response = $this->actingAs($actor)->get($route);

            $this->assertDeniedWithoutLeak($response, $victim, "{$who} minting");
        }

        // Guests too.
        $this->get("/seller/apply/documents/{$victim['document']->public_id}");
        $this->get("/admin/applications/documents/{$victim['document']->public_id}");

        // Every platform role that lacks the permission.
        foreach (AdminRole::cases() as $role) {
            if ($role->can(AdminPermission::SellerViewSensitive)) {
                continue;
            }

            $this->asAdmin($this->makeAdmin($role))
                ->get("/admin/applications/documents/{$victim['document']->public_id}");
        }

        $this->assertSame(
            [],
            $store->minted(),
            'A signed link was created for a caller who was then refused. '
                .'The refusal is not the boundary — the minting is.',
        );

    }

    #[Test]
    public function an_authorised_platform_reader_does_cause_exactly_one_link(): void
    {
        // The control for the test above. Kept separate so that "nothing
        // was minted" and "one thing was minted" are two statements about
        // two runs, rather than one run whose second half depends on the
        // first half's assertion having already been made.
        $victim = $this->applicantWithDocument('victim');
        $store = $this->signingStore();

        $this->asAdmin($this->makeAdmin(AdminRole::SellerOperations))
            ->get("/admin/applications/documents/{$victim['document']->public_id}")
            ->assertRedirect();

        $minted = $store->minted();

        $this->assertCount(1, $minted);
        $this->assertSame($victim['key'], $minted[0]['key']);
        $this->assertSame((int) config('veritas.storage.signed_url_seconds'), $minted[0]['seconds']);
    }

    #[Test]
    public function a_signed_link_is_a_bearer_capability_and_is_documented_as_one(): void
    {
        /*
         * Stated rather than tested as a flaw, because it is the design.
         *
         * A presigned object-storage URL is verified by the storage
         * provider, not by this application — it cannot know which browser
         * opened it, and asking it to would mean proxying every byte
         * through the application server. So it is reusable until it
         * expires, and the mitigations are the ones that are testable
         * here: it is short-lived, it is only ever minted for an
         * authorised caller, and it is never persisted, logged or put in
         * a prop.
         *
         * Signature tampering and expiry are enforced by the provider's
         * own verifier. Those are asserted against real object storage in
         * the separate R2 network gate, not faked here — a fake that
         * "rejected" a tampered signature would only be testing the fake.
         */
        $owner = $this->applicantWithDocument('owner');
        $store = $this->signingStore();

        $first = $this->actingAs($owner['user'])
            ->get("/seller/apply/documents/{$owner['document']->public_id}");
        $second = $this->actingAs($owner['user'])
            ->get("/seller/apply/documents/{$owner['document']->public_id}");

        $first->assertRedirect();
        $second->assertRedirect();

        // Each authorised request mints its own short-lived link rather
        // than reusing a stored one — there is no durable capability
        // sitting in a column waiting to be read.
        $this->assertCount(2, $store->minted());

        $this->assertSame(
            0,
            DB::table('seller_application_documents')
                ->where('path', 'like', '%X-Amz-Signature%')
                ->count(),
            'A signed URL was persisted.',
        );
    }

    // ── 3, 6 — a customer relationship is not a grant ─────────────────

    #[Test]
    public function buying_from_a_seller_does_not_open_their_paperwork(): void
    {
        $victim = $this->applicantWithDocument('victim', SellerApplicationStatus::Approved);
        $customer = User::factory()->create(['email' => 'loyal@example.test']);

        $response = $this->actingAs($customer)
            ->get("/seller/apply/documents/{$victim['document']->public_id}");

        $this->assertDeniedWithoutLeak($response, $victim, 'a customer of the seller');
    }

    // ── 7, 27 — membership is not a document permission ───────────────

    #[Test]
    public function seller_membership_does_not_grant_another_members_paperwork(): void
    {
        // The distinction §27 asks for, and it is sharper here than a role
        // matrix would be: application paperwork belongs to the person who
        // submitted it, so even the OWNER of the store cannot read a
        // colleague's identity documents. Membership makes you a member of
        // a store, not a reader of its people's passports.
        $victim = $this->applicantWithDocument('victim');

        foreach (SellerRole::cases() as $role) {
            ['user' => $member] = $this->makeSeller($role);

            $response = $this->actingAs($member)
                ->get("/seller/apply/documents/{$victim['document']->public_id}");

            $this->assertDeniedWithoutLeak(
                $response,
                $victim,
                "a seller {$role->value} reading a foreign application document",
            );
        }
    }

    // ── 8 — the platform matrix, from the permission and not a guess ──

    /** @return array<string, array{0: AdminRole, 1: bool}> */
    public static function adminRoles(): array
    {
        $cases = [];

        foreach (AdminRole::cases() as $role) {
            $cases[$role->value] = [$role, $role->can(AdminPermission::SellerViewSensitive)];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('adminRoles')]
    public function only_a_platform_role_holding_the_sensitive_permission_may_read(AdminRole $role, bool $permitted): void
    {
        $victim = $this->applicantWithDocument('victim');
        $admin = $this->makeAdmin($role);

        $response = $this->asAdmin($admin)
            ->get("/admin/applications/documents/{$victim['document']->public_id}");

        if ($permitted) {
            // The control half: the permission actually opens the door,
            // so the refusals below are the permission working rather
            // than the route being broken for everybody.
            $response->assertOk();
            $this->assertSame('the private contents of victim', $response->streamedContent());

            return;
        }

        $this->assertDeniedWithoutLeak($response, $victim, "platform role {$role->value}");
    }

    #[Test]
    public function reading_admin_pages_is_not_reading_paperwork(): void
    {
        // Named explicitly because it is the mistake this permission
        // exists to prevent: an analyst has the admin area, and must not
        // acquire identity documents along with it.
        $victim = $this->applicantWithDocument('victim');

        foreach ([AdminRole::Analyst, AdminRole::Support, AdminRole::FinanceAdmin, AdminRole::CatalogModerator] as $role) {
            $admin = $this->makeAdmin($role);

            $this->assertFalse(
                $role->can(AdminPermission::SellerViewSensitive),
                "{$role->value} has acquired the sensitive-document permission.",
            );

            $response = $this->asAdmin($admin)
                ->get("/admin/applications/documents/{$victim['document']->public_id}");

            $this->assertDeniedWithoutLeak($response, $victim, "{$role->value} with admin access");
        }
    }

    #[Test]
    public function the_sensitive_permission_is_checked_twice_and_independently(): void
    {
        /*
         * Defence in depth, asserted because it is easy to lose.
         *
         * Removing either the route middleware or the controller's own
         * check leaves the other one holding — which M9 confirmed by
         * removing them one at a time and finding the door still shut.
         * That is the property worth keeping: neither layer alone should
         * be the only thing between a role and a person's passport, and a
         * future refactor that deletes "the redundant check" should have
         * to notice it is doing that.
         */
        $route = app('router')->getRoutes()->getByName('admin.applications.documents.show');

        $this->assertNotNull($route);
        $this->assertContains(
            'admin.can:seller.view_sensitive',
            $route->gatherMiddleware(),
            'The route lost its permission gate.',
        );

        $controller = (string) file_get_contents(
            base_path('app/Modules/AdminPortal/Http/Controllers/ApplicationDocumentController.php'),
        );

        $this->assertStringContainsString(
            'AdminPermission::SellerViewSensitive',
            $controller,
            'The controller stopped checking the permission for itself.',
        );
    }

    // ── 11, 13, 14 — the key, the filename, the path ──────────────────

    #[Test]
    public function knowing_the_storage_key_is_not_a_way_in(): void
    {
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');

        // The private disk has no public route at all — `serve => false`
        // and no `url` — so there is nothing to address. These are the
        // shapes somebody would try anyway.
        foreach ([
            '/storage/'.$victim['key'],
            '/storage/documents/'.$victim['key'],
            '/'.$victim['key'],
        ] as $url) {
            $response = $this->actingAs($attacker['user'])->get($url);

            $this->assertNotSame(200, $response->getStatusCode(), "The private key was addressable at {$url}.");
            $this->assertStringNotContainsString('the private contents of victim', (string) $response->getContent());
        }
    }

    #[Test]
    public function the_original_filename_is_not_an_identifier(): void
    {
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');

        foreach ([
            'victim-passport.pdf',
            rawurlencode('victim-passport.pdf'),
            'passport.pdf',
        ] as $filename) {
            $response = $this->actingAs($attacker['user'])->get('/seller/apply/documents/'.$filename);

            $this->assertDeniedWithoutLeak($response, $victim, "lookup by filename {$filename}");
        }
    }

    #[Test]
    public function traversal_shaped_identifiers_cannot_escape_the_document_namespace(): void
    {
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');

        foreach ([
            '../'.$victim['document']->public_id,
            '..%2F'.$victim['document']->public_id,
            '....//'.$victim['document']->public_id,
            '/etc/passwd',
            '..%252F..%252Fetc%252Fpasswd',
            $victim['key'].'/../'.$attacker['key'],
        ] as $attempt) {
            $response = $this->actingAs($attacker['user'])->get('/seller/apply/documents/'.$attempt);

            $this->assertNotSame(
                200,
                $response->getStatusCode(),
                "A traversal-shaped id resolved: {$attempt}",
            );
            $this->assertStringNotContainsString('the private contents of victim', (string) $response->getContent());
            $this->assertStringNotContainsString('root:', (string) $response->getContent());
        }
    }

    // ── 24 — cross-tenant mutation ────────────────────────────────────

    #[Test]
    public function one_applicant_cannot_delete_or_replace_anothers_document(): void
    {
        $victim = $this->applicantWithDocument('victim');
        $attacker = $this->applicantWithDocument('attacker');

        $response = $this->actingAs($attacker['user'])
            ->delete("/seller/apply/documents/{$victim['document']->public_id}");

        $this->assertContains($response->getStatusCode(), [403, 404]);

        // Still there, still theirs, still readable by its owner. A
        // deletion that "failed" but unlinked the object would pass a
        // status check and fail the person whose compliance file it was.
        $this->assertDatabaseHas('seller_application_documents', [
            'id' => $victim['document']->id,
            'seller_application_id' => $victim['application']->id,
        ]);

        $this->actingAs($victim['user'])
            ->get("/seller/apply/documents/{$victim['document']->public_id}")
            ->assertOk();

        // There is no replace/rename/visibility route for a document, so
        // those are not applicable rather than untested: the only
        // mutations are upload and remove.
        $this->assertTrue(app('router')->getRoutes()->hasNamedRoute('seller.apply.documents.destroy'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('seller.apply.documents.update'));
    }

    // ── 26 — the lifecycle never opens the file ───────────────────────

    #[Test]
    public function no_application_status_makes_paperwork_public(): void
    {
        foreach (SellerApplicationStatus::cases() as $status) {
            $victim = $this->applicantWithDocument('victim'.$status->value, $status);
            $attacker = $this->applicantWithDocument('attacker'.$status->value);

            $response = $this->actingAs($attacker['user'])
                ->get("/seller/apply/documents/{$victim['document']->public_id}");

            $this->assertDeniedWithoutLeak($response, $victim, "a {$status->value} application");

            // And a guest, because "approved" is the status somebody would
            // most plausibly treat as published.
            $guest = $this->get("/seller/apply/documents/{$victim['document']->public_id}");
            $this->assertNotSame(200, $guest->getStatusCode(), "A guest read a {$status->value} application's document.");
        }
    }

    #[Test]
    public function a_decided_application_keeps_its_documents_readable_by_their_owner(): void
    {
        // The other half of the lifecycle rule: compliance history does
        // not disappear when an application is decided. §19 is explicit
        // that finance and compliance records stay readable.
        foreach ([SellerApplicationStatus::Approved, SellerApplicationStatus::Rejected] as $status) {
            $owner = $this->applicantWithDocument('owner'.$status->value, $status);

            $this->actingAs($owner['user'])
                ->get("/seller/apply/documents/{$owner['document']->public_id}")
                ->assertOk();
        }
    }

    // ── 17, 18 — nothing private in an unrelated response ─────────────

    #[Test]
    public function no_unrelated_page_carries_private_document_metadata(): void
    {
        $victim = $this->applicantWithDocument('victim', SellerApplicationStatus::Approved);
        $customer = User::factory()->create(['email' => 'browser@example.test']);

        $pages = [
            '/' => null,
            '/account' => $customer,
            '/account/orders' => $customer,
            '/cart' => null,
        ];

        foreach ($pages as $url => $actor) {
            $response = $actor === null ? $this->get($url) : $this->actingAs($actor)->get($url);

            $body = (string) $response->getContent();

            $this->assertStringNotContainsString($victim['key'], $body, "{$url} carried a private storage key.");
            $this->assertStringNotContainsString(
                (string) $victim['document']->original_name,
                $body,
                "{$url} carried a private document filename.",
            );
            $this->assertStringNotContainsString('seller-documents', $body, "{$url} named the private collection.");
        }
    }

    // ── 20, 21 — the response itself ──────────────────────────────────

    #[Test]
    public function a_streamed_document_is_uncacheable_and_its_filename_cannot_inject_headers(): void
    {
        $owner = $this->applicantWithDocument('owner');

        // A hostile name that never came through the uploader — a
        // backfill, an importer, an admin correction. The uploader strips
        // this today; the header builder must not depend on that.
        $owner['document']->forceFill([
            'original_name' => "ok\r\nX-Injected: yes\r\n\r\n<script>alert(1)</script>.pdf",
        ])->save();

        $response = $this->actingAs($owner['user'])
            ->get("/seller/apply/documents/{$owner['document']->public_id}");

        $response->assertOk();

        $disposition = (string) $response->baseResponse->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment;', $disposition, 'A private document is never rendered inline.');
        $this->assertStringNotContainsString("\r", $disposition, 'A carriage return reached a response header.');
        $this->assertStringNotContainsString("\n", $disposition, 'A newline reached a response header.');
        $this->assertStringNotContainsString('X-Injected: yes', $response->baseResponse->headers->get('Content-Disposition') ?? '');
        $this->assertNull($response->baseResponse->headers->get('X-Injected'));

        $cacheControl = (string) $response->baseResponse->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertSame('nosniff', $response->baseResponse->headers->get('X-Content-Type-Options'));
    }

    // ── 11, 22 — the private disk is private, structurally ────────────

    #[Test]
    public function verification_documents_are_bound_to_the_private_abstraction(): void
    {
        /*
         * The rule a future change must break a test to break: seller
         * paperwork uses the private visibility, and the private disk has
         * no public URL to switch to.
         *
         * Asserted against the configuration and the store's own contract
         * rather than against a call site, because the accident being
         * prevented is somebody adding a NEW call site that reaches for
         * the public disk.
         */
        $private = config('veritas.storage.private_disk');
        $public = config('veritas.storage.public_disk');

        $this->assertNotSame($private, $public, 'Private and public documents share a disk.');
        $this->assertSame($private, Visibility::Private->disk());
        $this->assertSame($public, Visibility::Public->disk());

        $this->assertSame('private', config("filesystems.disks.{$private}.visibility"));
        $this->assertFalse(
            (bool) config("filesystems.disks.{$private}.serve", false),
            'The private disk is served over HTTP.',
        );
        $this->assertNull(
            config("filesystems.disks.{$private}.url"),
            'The private disk has a public URL base.',
        );

        // And the store refuses to produce a permanent URL for a private
        // object at all, rather than returning one nobody checks.
        $stored = app(ObjectStore::class)->putContents('x', 'seller-documents', 'application/pdf', Visibility::Private);

        $this->expectException(RuntimeException::class);
        app(ObjectStore::class)->url($stored);
    }

    #[Test]
    public function the_upload_path_stores_paperwork_privately_and_nowhere_else(): void
    {
        $owner = $this->applicantWithDocument('owner');

        $this->assertSame(
            config('veritas.storage.private_disk'),
            $owner['document']->disk,
            'A verification document was written to a disk that is not the private one.',
        );

        // Every document row in the system, not just this one.
        $disks = SellerApplicationDocument::query()->distinct()->pluck('disk')->all();

        $this->assertSame([config('veritas.storage.private_disk')], $disks);
    }

    // ── 23 — authorise, then mint ─────────────────────────────────────

    #[Test]
    public function the_capability_is_created_only_after_ownership_is_resolved(): void
    {
        /*
         * Ordering, asserted structurally. The download resolver takes a
         * document — an already-resolved, already-owned one — and there is
         * no path into it that takes an id. Something that accepted an
         * identifier would be minting first and checking second, which
         * leaks the capability into logs and traces even when the response
         * is a 403.
         */
        $parameters = array_map(
            static fn (ReflectionParameter $p): string => (string) $p->getType(),
            (new ReflectionMethod(ResolveDocumentDownload::class, '__invoke'))->getParameters(),
        );

        $this->assertSame([SellerApplicationDocument::class], $parameters);

        // And the TTL is bounded and configured in one place, rather than
        // being a literal somebody can quietly raise at a call site.
        $seconds = (int) config('veritas.storage.signed_url_seconds');

        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(900, $seconds, 'A private link should outlive the click, not the session.');

        $source = (string) file_get_contents(
            base_path('app/Modules/Sellers/Queries/ResolveDocumentDownload.php'),
        );

        $this->assertStringContainsString("config('veritas.storage.signed_url_seconds')", $source);
        $this->assertSame(
            0,
            preg_match('/temporaryUrl\s*\(\s*\$\w+\s*,\s*\d+/', $source),
            'The link lifetime is a literal rather than the configured one.',
        );
    }

    // ── 19 — the capability never reaches a log ───────────────────────

    #[Test]
    public function a_signed_link_is_never_written_to_a_log(): void
    {
        // The resolver hands the URL to the browser and keeps no copy: it
        // neither logs nor persists it. Asserted structurally because the
        // leak this prevents is a line added later "for debugging", and
        // the bearer capability is precisely what must not be in a log
        // aggregator six months from now.
        $source = (string) file_get_contents(
            base_path('app/Modules/Sellers/Queries/ResolveDocumentDownload.php'),
        );

        foreach (['Log::', 'logger(', 'info(', 'report(', 'dump(', 'ray('] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $source,
                "The download resolver calls {$call} — a signed link must not reach a log.",
            );
        }
    }
}
