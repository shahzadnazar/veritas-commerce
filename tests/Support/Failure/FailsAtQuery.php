<?php

declare(strict_types=1);

namespace Tests\Support\Failure;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fail at a chosen point inside a transaction, from outside the code.
 *
 * The rollback drills need a failure after some of a transaction has
 * happened and before the rest of it has, and the obvious ways to arrange
 * that are all worse than this one. A production flag that makes a
 * payment fail halfway is a bigger defect than anything the drill could
 * find. Swapping a collaborator does not work either: every action in
 * this codebase is `final` and constructor-typed, so a container binding
 * of the wrong type is refused by PHP before the transaction even opens —
 * which is how the first version of these drills passed while proving
 * nothing.
 *
 * So the seam is the query log. A listener that throws when it sees a
 * particular statement fires *after* that statement has run and inside
 * the transaction that ran it, which is exactly the moment of interest:
 * the row exists, the transaction is open, and something has just gone
 * wrong. Nothing in `app/` knows this exists.
 *
 * Fires once. A rollback re-runs nothing, but a retry in the same test
 * would otherwise hit the same fault forever.
 */
final class FailsAtQuery
{
    /**
     * Throw the first time a statement containing `$fragment` runs.
     *
     * The fragment is matched against the SQL Eloquent generated, so it
     * names a table and an operation — `insert into "payments"` — rather
     * than a line of PHP. That keeps the drill readable as a statement
     * about the database rather than about the implementation.
     */
    public static function containing(string $fragment, string $because = 'Injected failure inside a transaction.'): void
    {
        $fired = false;

        DB::listen(static function (QueryExecuted $query) use ($fragment, $because, &$fired): void {
            if ($fired || ! str_contains($query->sql, $fragment)) {
                return;
            }

            $fired = true;

            throw new RuntimeException($because);
        });
    }
}
