<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two indexes the M9 plan audit asked for, and one it replaces.
 *
 * Both were found the same way: the read surfaces were run against a
 * launch-sized database under `EXPLAIN (ANALYZE, BUFFERS)` and the plans
 * were read. Neither is speculative, and no index was added merely
 * because a column was a foreign key — `payout_settlement_attempts`,
 * `payout_status_history` and `shipment_items` all carry foreign keys
 * that no measured query looks up on their own, so they are left alone.
 *
 * NOT a zero-lock migration in the ordinary sense, and not one either
 * way by accident: `CREATE INDEX` takes a lock that blocks writes to the
 * table for as long as the build takes, which on an append-only
 * financial ledger at production volume is not acceptable. So these are
 * built `CONCURRENTLY`, which does not block writes, at the cost of two
 * table passes and of not being transactional — hence
 * `$withinTransaction = false`.
 *
 * The consequence of that trade is worth stating plainly, because it is
 * the thing that bites during an incident: a `CONCURRENTLY` build that
 * fails partway leaves an INVALID index behind. It is not used by the
 * planner and it is not corrupt, but it does consume writes. Drop it and
 * run the migration again. `IF NOT EXISTS` will not do that for you: an
 * invalid index exists.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        /*
         * Finding: the admin payout detail page read the entire seller
         * ledger — 53,407 rows, 2,486 pages, 5.5 ms — to find the single
         * settlement debit belonging to one payout. There was no index on
         * `payout_request_id` at all, so the cost of opening any payout
         * grew with the lifetime size of the ledger.
         *
         * Partial, because the column is null on every earning and set
         * only on the payout debits: 1,836 rows out of 53,407 here, and a
         * far smaller fraction as the ledger accumulates. Every query
         * that reads it is an equality match, which excludes null anyway,
         * so the partial index loses nothing.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS seller_ledger_entries_payout_request_id_index
            ON seller_ledger_entries (payout_request_id)
            WHERE payout_request_id IS NOT NULL
            SQL);

        /*
         * Finding: the seller inventory list sorts by
         * `inventory_balances.available`, which no index can order, so
         * the planner gave up on the join and hash-joined the whole
         * balances table — 28,170 rows scanned to return the 220 that
         * belong to the seller.
         *
         * The existing index already led on `(offer_id, available)`; what
         * it could not do was answer the query without going back to the
         * heap for `on_hand` and `reserved`, and that heap cost is what
         * made the sequential scan look cheaper. Carrying the two columns
         * as payload turns the join back into a nested loop over an
         * index-only scan: 3.04 ms became 0.50 ms, 1,366 pages became
         * 452, with zero heap fetches.
         *
         * It replaces the old index rather than joining it. The key
         * columns are identical, so anything the old one could serve the
         * new one serves too — and a second index on a table written on
         * every reservation, sale and restock would be a real write cost
         * for no read that needs it.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS inventory_balances_available_covering
            ON inventory_balances (offer_id, available)
            INCLUDE (on_hand, reserved)
            SQL);

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS inventory_balances_available');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS inventory_balances_available
            ON inventory_balances (offer_id, available)
            SQL);

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS inventory_balances_available_covering');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS seller_ledger_entries_payout_request_id_index');
    }
};
