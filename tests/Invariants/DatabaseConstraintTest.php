<?php

declare(strict_types=1);

namespace Tests\Invariants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The schema's own guarantees, asserted against the live catalogue rather
 * than against the migrations that were supposed to produce it.
 *
 * These are the properties a migration can quietly drop. Not "is there an
 * index here" — that is a performance question, answered by measurement,
 * and adding one on principle is how tables end up with more index than
 * heap. These are the ones where the default is a defect: a foreign key
 * with no delete behaviour, a table with no primary key, money in a
 * floating-point column.
 *
 * Read from `pg_catalog` because that is the only source that cannot be
 * out of date. A migration file says what somebody intended; the
 * catalogue says what the database is.
 */
final class DatabaseConstraintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every foreign key says what happens when the parent goes.
     *
     * PostgreSQL's default is NO ACTION, which is not a decision — it is
     * the absence of one, and it surfaces at three in the morning as a
     * constraint violation on a delete somebody expected to work. Cascade,
     * restrict and set-null are all fine answers; not having answered is
     * not.
     */
    #[Test]
    public function every_foreign_key_declares_what_a_parent_deletion_does(): void
    {
        /** @var array<int, object{name: string, table: string}> $undeclared */
        $undeclared = DB::select(<<<'SQL'
            SELECT c.conname AS name, t.relname AS table
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace AND n.nspname = 'public'
            WHERE c.contype = 'f' AND c.confdeltype = 'a'
            ORDER BY t.relname, c.conname
            SQL);

        $this->assertSame([], array_map(
            static fn (object $row): string => "{$row->table}.{$row->name}",
            $undeclared,
        ), 'These foreign keys fall back to NO ACTION. Say ON DELETE CASCADE, RESTRICT or SET NULL.');
    }

    /**
     * A `SET NULL` foreign key on a `NOT NULL` column is a delete that
     * cannot succeed.
     *
     * The two clauses are written in different places — the column in one
     * migration, the constraint in another — so nothing but a check like
     * this notices when they contradict each other. The failure is
     * invisible until somebody deletes a parent, at which point the
     * delete fails and no amount of reading the child table explains why.
     */
    #[Test]
    public function no_foreign_key_promises_to_null_a_column_that_cannot_be_null(): void
    {
        /** @var array<int, object{table: string, column: string}> $contradictions */
        $contradictions = DB::select(<<<'SQL'
            SELECT t.relname AS table, a.attname AS column
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace AND n.nspname = 'public'
            JOIN unnest(c.conkey) AS k(attnum) ON true
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
            WHERE c.contype = 'f' AND c.confdeltype = 'n' AND a.attnotnull
            ORDER BY t.relname, a.attname
            SQL);

        $this->assertSame([], array_map(
            static fn (object $row): string => "{$row->table}.{$row->column}",
            $contradictions,
        ), 'ON DELETE SET NULL on a NOT NULL column: the parent can never be deleted.');
    }

    #[Test]
    public function every_table_has_a_primary_key(): void
    {
        /** @var array<int, object{relname: string}> $keyless */
        $keyless = DB::select(<<<'SQL'
            SELECT t.relname
            FROM pg_class t
            JOIN pg_namespace n ON n.oid = t.relnamespace AND n.nspname = 'public'
            WHERE t.relkind = 'r'
              AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conrelid = t.oid AND c.contype = 'p')
            ORDER BY t.relname
            SQL);

        $this->assertSame([], array_map(
            static fn (object $row): string => $row->relname,
            $keyless,
        ), 'A table with no primary key cannot be replicated, deduplicated or reliably updated.');
    }

    /**
     * Money is an integer count of minor units, in the database as well as
     * in `App\Support\Money`.
     *
     * A `numeric` column would survive the arithmetic and a `double
     * precision` one would not, but the reason to insist on `bigint` is
     * narrower than either: every amount in this system is already an
     * integer number of cents by the time it reaches the database, and a
     * column that accepts anything else is an invitation for something
     * upstream to stop rounding.
     */
    #[Test]
    public function every_money_column_is_a_whole_number_of_minor_units(): void
    {
        /** @var array<int, object{table_name: string, column_name: string, data_type: string}> $wrong */
        $wrong = DB::select(<<<'SQL'
            SELECT table_name, column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND column_name LIKE '%\_minor' AND data_type <> 'bigint'
            ORDER BY table_name, column_name
            SQL);

        $this->assertSame([], array_map(
            static fn (object $row): string => "{$row->table_name}.{$row->column_name} is {$row->data_type}",
            $wrong,
        ));
    }

    /**
     * Nothing anywhere in the schema is a float.
     *
     * Wider than the money check on purpose. A commission rate stored as
     * `double precision` is the same defect one step removed: it is
     * multiplied by an amount, and 0.1 + 0.2 is still not 0.3. The rate
     * columns here are `numeric`, which is exact.
     */
    #[Test]
    public function nothing_stores_a_number_as_a_float(): void
    {
        /** @var array<int, object{table_name: string, column_name: string, data_type: string}> $floats */
        $floats = DB::select(<<<'SQL'
            SELECT table_name, column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND data_type IN ('double precision', 'real', 'money')
            ORDER BY table_name, column_name
            SQL);

        $this->assertSame([], array_map(
            static fn (object $row): string => "{$row->table_name}.{$row->column_name} is {$row->data_type}",
            $floats,
        ));
    }
}
