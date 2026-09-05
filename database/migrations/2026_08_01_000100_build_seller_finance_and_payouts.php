<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller finance: where money is held, and what it took to move it.
 *
 * M0 sketched `payout_requests` and a `seller_bank_accounts` table nothing
 * ever wrote to. The sketch was right about one thing — the partial unique
 * index that allows a seller only one open request — and that index is
 * preserved here untouched. Everything else is built out.
 *
 * The central decision is that a payout hold is NOT a ledger entry. M0
 * posted a negative `payout_reservation` row to hold the money, which reads
 * naturally until settlement, when the real payout debit is posted beside
 * it and the seller's balance falls by the amount twice. A hold and a
 * payment are different facts: one says "this money is spoken for", the
 * other says "this money is gone". So the hold lives in
 * `payout_allocations`, which also answers the question a single reserved
 * total cannot — WHICH earnings paid for this payout — and the ledger keeps
 * exactly one debit per settlement.
 *
 * Allocations also make the arithmetic checkable rather than trusted:
 * sum(held) for a seller is the reservation, sum(settled) against a payout
 * equals the debit that settled it, and a rejected request has neither.
 * `finance:reconcile-sellers` asserts all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old destination FK goes first: PostgreSQL will not drop a
        // table another table still points at.
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seller_bank_account_id');
        });

        $this->buildPayoutAccounts();
        $this->extendPayoutRequests();
        $this->buildAllocations();
        $this->buildHistoryAndAttempts();
    }

    /**
     * Where a payout goes, as an abstraction over how it gets there.
     *
     * The M0 table is replaced rather than extended: it modelled a bank
     * account specifically — holder name, last4, an encrypted blob of
     * details — and Phase 1 does not move money over a bank rail at all.
     * What the domain needs is a destination it can name in a payout
     * record and hand to a provider later, which is a different shape.
     *
     * Nothing here is a credential. `provider_account_reference` is an
     * identifier a provider issues (a Connect account id, when that
     * exists); `last4` and `display_label` are for a human to recognise
     * which destination they picked. A password, a full account number or
     * a card verification value has no column to live in, deliberately.
     */
    private function buildPayoutAccounts(): void
    {
        Schema::dropIfExists('seller_bank_accounts');

        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();

            // manual | bank_transfer | provider — how settlement happens.
            $table->string('type')->default('manual');

            // The adapter that will move the money. Phase 1 is 'manual':
            // a person makes the transfer and records the reference.
            $table->string('provider')->default('manual');
            $table->string('provider_account_reference')->nullable();

            $table->string('display_label');
            $table->string('last4', 4)->nullable();
            $table->char('country', 2)->nullable();
            $table->char('currency', 3)->default('USD');

            $table->string('status')->default('active');         // active | disabled
            $table->timestamp('verified_at')->nullable();

            // Surfaced on the payout queue: a destination that changed
            // days before a withdrawal is the oldest fraud pattern there
            // is, and finance should see it without asking.
            $table->timestamp('changed_at')->nullable();

            $table->timestamps();

            $table->index(['seller_account_id', 'status']);
        });

        // One default destination per seller in Phase 1, which is what
        // makes "the seller's payout account" an unambiguous phrase.
        DB::statement(
            'create unique index payout_accounts_one_active_per_seller
             on payout_accounts (seller_account_id)
             where status = \'active\''
        );
    }

    /**
     * The request itself, and everything a later reader needs from it.
     *
     * The snapshot columns are the point. A payout is read months after
     * it happened, by which time the store may have been renamed and the
     * destination replaced; a record that joins to current rows to
     * describe a past decision quietly rewrites history. So the label the
     * seller saw when they chose it is copied here and never updated.
     *
     * The four actor columns are the four-eyes seam. Phase 1 lets one
     * finance admin review, approve and settle — inventing mandatory dual
     * control nobody asked for would only teach people to share a login —
     * but the columns record who did each, so a policy requiring
     * different people is a check to add rather than a schema to change.
     */
    private function extendPayoutRequests(): void
    {
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->foreignId('payout_account_id')->nullable()->after('seller_account_id')
                ->constrained('payout_accounts')->nullOnDelete();

            // Snapshots, written once and never refreshed.
            $table->string('destination_label')->nullable()->after('payout_account_id');
            $table->string('destination_type')->nullable()->after('destination_label');
            $table->string('seller_name_snapshot')->nullable()->after('destination_type');

            $table->foreignId('requested_by_user_id')->nullable()->after('requested_at')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable()->after('requested_by_user_id');
            $table->foreignId('reviewed_by_admin_id')->nullable()->after('reviewed_at')
                ->constrained('admin_users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable()->after('reviewed_by_admin_id');
            $table->foreignId('approved_by_admin_id')->nullable()->after('approved_at')
                ->constrained('admin_users')->nullOnDelete();

            $table->timestamp('paid_at')->nullable()->after('approved_by_admin_id');
            $table->foreignId('settled_by_admin_id')->nullable()->after('paid_at')
                ->constrained('admin_users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable()->after('settled_by_admin_id');
            $table->timestamp('failed_at')->nullable()->after('cancelled_at');

            // How the money actually left, recorded at settlement.
            $table->string('settlement_method')->nullable()->after('settlement_ref');

            $table->index(['seller_account_id', 'status']);
            $table->index(['currency', 'status']);
        });

        // A request is for a positive amount of money. Zero is not a
        // withdrawal and a negative one is a deposit nobody authorised.
        DB::statement(
            'alter table payout_requests
             add constraint payout_requests_amount_is_positive check (amount_minor > 0)'
        );
    }

    /**
     * Which earnings paid for which payout, and whether they still hold.
     *
     * HELD is a live reservation and comes out of withdrawable. SETTLED
     * means the payout it belongs to was paid — the hold is gone and the
     * ledger debit has taken its place, which is why closing the hold and
     * posting the debit do not both reduce the balance. RELEASED means the
     * request was rejected or cancelled and the money went back.
     *
     * The row is never deleted in any of the three cases. "This $200 was
     * reserved for a payout that was rejected on the 4th" is a fact worth
     * keeping, and a request whose allocations vanished is a request
     * nobody can audit.
     */
    private function buildAllocations(): void
    {
        Schema::create('payout_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_ledger_entry_id')->constrained('seller_ledger_entries')->restrictOnDelete();

            // Denormalised so the reservation total for a seller is one
            // indexed read rather than a join through their whole ledger.
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();

            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');
            $table->string('status')->default('held');           // held | settled | released

            $table->timestamp('created_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->index(['seller_account_id', 'status', 'currency']);
            $table->index(['payout_request_id', 'status']);
        });

        // An allocation takes a positive amount out of an earning. The
        // sign convention is deliberate: allocations are amounts held,
        // ledger entries are amounts moved, and mixing the two signs is
        // how a reservation ends up added to a balance it should reduce.
        DB::statement(
            'alter table payout_allocations
             add constraint payout_allocations_amount_is_positive check (amount_minor > 0)'
        );

        // One allocation per (request, earning). A retried request that
        // somehow reached the insert twice writes one row, not two.
        DB::statement(
            'create unique index payout_allocations_one_per_entry
             on payout_allocations (payout_request_id, seller_ledger_entry_id)'
        );
    }

    /**
     * What happened to the request, and every attempt to settle it.
     *
     * Settlement attempts are separate rows rather than columns on the
     * request because a failed attempt is not a draft of the successful
     * one — it is a thing that happened, with its own reference and its
     * own reason, and overwriting it loses the only record that the money
     * was tried once already. Phase 1 usually writes exactly one; a future
     * provider will write several, and will not need a new table.
     */
    private function buildHistoryAndAttempts(): void
    {
        Schema::create('payout_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            $table->string('actor_type')->nullable();            // seller | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['payout_request_id', 'id']);
        });

        Schema::create('payout_settlement_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete();

            $table->string('provider')->default('manual');
            $table->string('method')->nullable();                // wire | ach | paypal | other
            $table->string('external_reference')->nullable();

            $table->string('status')->default('initiated');      // initiated | succeeded | failed

            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');

            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();

            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            $table->foreignId('initiated_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();

            $table->index(['payout_request_id', 'id']);
        });

        /*
         * One successful settlement per payout, enforced by the database.
         *
         * Two finance admins pressing "mark paid" at the same moment is
         * the race that pays a seller twice, and the domain's row lock is
         * the first answer to it. This index is the answer that holds when
         * the lock is bypassed, the process is replaced, or someone writes
         * a repair script at 2am.
         */
        DB::statement(
            'create unique index payout_settlement_attempts_one_success
             on payout_settlement_attempts (payout_request_id)
             where status = \'succeeded\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_settlement_attempts');
        Schema::dropIfExists('payout_status_history');
        Schema::dropIfExists('payout_allocations');

        DB::statement('alter table payout_requests drop constraint if exists payout_requests_amount_is_positive');

        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_account_id');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropConstrainedForeignId('reviewed_by_admin_id');
            $table->dropConstrainedForeignId('approved_by_admin_id');
            $table->dropConstrainedForeignId('settled_by_admin_id');
            $table->dropColumn([
                'destination_label', 'destination_type', 'seller_name_snapshot',
                'reviewed_at', 'approved_at', 'paid_at', 'cancelled_at',
                'failed_at', 'settlement_method',
            ]);
        });

        Schema::dropIfExists('payout_accounts');
    }
};
