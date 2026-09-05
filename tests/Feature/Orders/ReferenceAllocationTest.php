<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Orders\Actions\AllocateReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first reference of a sequence, allocated twice at once.
 *
 * Every later allocation is serialised by a row lock. The first one
 * cannot be: there is no row to lock yet, so concurrent callers all read
 * null and all try to create it. Before this was handled the losers hit a
 * unique violation on `reference_sequences`, which reaches a customer as
 * a five-hundred at the moment they place their order.
 *
 * The M9 contention drill found it by sending forty simultaneous
 * checkouts at a database whose orders had been loaded without their
 * sequences — which is also what a restore looks like. A new deployment's
 * first concurrent orders are the other way in.
 *
 * The race is reproduced here without threads: a query listener creates
 * the row in the same transaction between the read that found nothing and
 * the write that follows it, which is the state a losing caller is in.
 */
final class ReferenceAllocationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function losing_the_race_to_create_a_sequence_still_allocates(): void
    {
        DB::table('reference_sequences')->where('name', 'marketplace_order')->delete();

        $raced = false;

        DB::listen(function ($query) use (&$raced): void {
            if ($raced || ! str_contains($query->sql, 'reference_sequences')) {
                return;
            }

            if (! str_contains($query->sql, 'for update')) {
                return;
            }

            // The winner's row, landing after our read returned nothing.
            $raced = true;
            DB::table('reference_sequences')->insert(['name' => 'marketplace_order', 'next_value' => 1]);
        });

        $reference = app(AllocateReference::class)->orderReference();

        $this->assertTrue($raced, 'The listener never saw the locking read, so nothing was raced.');
        $this->assertNotSame('', $reference);

        // The winner's row was adopted rather than duplicated, and the
        // sequence moved on exactly once.
        $this->assertSame(1, DB::table('reference_sequences')->where('name', 'marketplace_order')->count());
        $this->assertSame(2, (int) DB::table('reference_sequences')->where('name', 'marketplace_order')->value('next_value'));
    }

    #[Test]
    public function references_stay_dense_across_allocations(): void
    {
        DB::table('reference_sequences')->where('name', 'marketplace_order')->delete();

        $allocate = app(AllocateReference::class);
        $first = $allocate->orderReference();
        $second = $allocate->orderReference();

        $this->assertNotSame($first, $second);
        $this->assertSame(3, (int) DB::table('reference_sequences')->where('name', 'marketplace_order')->value('next_value'));
    }

    /**
     * Each kind of reference counts on its own.
     *
     * They share a table, so a bug in the row lookup would show up as an
     * order and a payout handing out the same number.
     */
    #[Test]
    public function each_sequence_is_independent(): void
    {
        DB::table('reference_sequences')->delete();

        $allocate = app(AllocateReference::class);
        $allocate->orderReference();
        $allocate->orderReference();
        $allocate->payoutReference();

        $this->assertSame(3, (int) DB::table('reference_sequences')->where('name', 'marketplace_order')->value('next_value'));
        $this->assertSame(2, (int) DB::table('reference_sequences')->where('name', 'payout_request')->value('next_value'));
    }
}
