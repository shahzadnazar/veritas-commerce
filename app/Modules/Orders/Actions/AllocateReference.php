<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Support\Reference;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next human-facing reference.
 *
 * A dedicated sequence table rather than the identity column, so VC- numbers
 * stay dense and readable — a customer quoting "VC-24081" to support should
 * not be quoting a number with gaps where rolled-back transactions were.
 */
final class AllocateReference
{
    public function orderReference(): string
    {
        return Reference::order($this->next('marketplace_order'));
    }

    public function applicationReference(): string
    {
        return Reference::application($this->next('seller_application'));
    }

    public function payoutReference(): string
    {
        return Reference::payout($this->next('payout_request'));
    }

    public function refundReference(): string
    {
        return Reference::refund($this->next('refund'));
    }

    private function next(string $name): int
    {
        return DB::transaction(function () use ($name): int {
            $row = DB::table('reference_sequences')->where('name', $name)->lockForUpdate()->first();

            if ($row === null) {
                /*
                 * The first allocation of a sequence is the one case the
                 * row lock above cannot serialise, because there is no row
                 * yet to lock: every concurrent caller reads null and every
                 * one of them tries to create it. A plain insert makes the
                 * losers fail with a unique violation, which reaches the
                 * customer as a five-hundred on their order — rare in a
                 * long-lived deployment, certain on the first burst of a
                 * new one, and on any database restored from rows that were
                 * loaded without their sequences.
                 *
                 * Ignoring the conflict turns the race into a wait: the
                 * losers block on the winner's uncommitted row, do nothing
                 * once it lands, and then take the lock on it properly.
                 */
                DB::table('reference_sequences')->insertOrIgnore(['name' => $name, 'next_value' => 1]);

                $row = DB::table('reference_sequences')->where('name', $name)->lockForUpdate()->first();
            }

            if ($row === null) {
                throw new RuntimeException("The {$name} reference sequence could not be created.");
            }

            DB::table('reference_sequences')->where('name', $name)->update([
                'next_value' => $row->next_value + 1,
            ]);

            return (int) $row->next_value;
        });
    }
}
