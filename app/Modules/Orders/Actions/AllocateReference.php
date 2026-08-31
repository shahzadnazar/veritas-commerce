<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Support\Reference;
use Illuminate\Support\Facades\DB;

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
                DB::table('reference_sequences')->insert(['name' => $name, 'next_value' => 2]);

                return 1;
            }

            DB::table('reference_sequences')->where('name', $name)->update([
                'next_value' => $row->next_value + 1,
            ]);

            return (int) $row->next_value;
        });
    }
}
