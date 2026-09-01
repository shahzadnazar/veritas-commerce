<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Enums\ReservationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the fast numbers still equal the slow ones.
 *
 * `reserved` is a column because discovery cannot afford a correlated SUM
 * per product card. A cached number is only safe if something checks it,
 * so this recomputes both quantities from their sources and reports every
 * balance that disagrees:
 *
 *   on_hand  == SUM(inventory_movements.on_hand_change)
 *   reserved == SUM(inventory_movements.reserved_change)
 *   reserved == SUM(held reservations.quantity)
 *
 * Read-only by default. `--fix` repairs `reserved` from the reservation
 * rows, which are the operational truth; it deliberately will not touch
 * on_hand, because a mismatch there means the append-only ledger and the
 * balance disagree, and that is an incident for a person, not a script.
 */
final class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile {--fix : Repair reserved from the live reservation rows}';

    protected $description = 'Check every inventory balance against its movement ledger and reservations';

    public function handle(): int
    {
        /** @var array<int, object{id: int, offer_id: int, on_hand: int, reserved: int, ledger_on_hand: int, ledger_reserved: int, held: int}> $rows */
        $rows = DB::select('
            select b.id,
                   b.offer_id,
                   b.on_hand,
                   b.reserved,
                   coalesce(m.on_hand_sum, 0)  as ledger_on_hand,
                   coalesce(m.reserved_sum, 0) as ledger_reserved,
                   coalesce(r.held_sum, 0)     as held
              from inventory_balances b
              left join (
                    select offer_id,
                           inventory_location_id,
                           sum(on_hand_change)  as on_hand_sum,
                           sum(reserved_change) as reserved_sum
                      from inventory_movements
                     group by offer_id, inventory_location_id
              ) m on m.offer_id = b.offer_id and m.inventory_location_id = b.inventory_location_id
              left join (
                    select offer_id,
                           inventory_location_id,
                           sum(quantity) as held_sum
                      from inventory_reservations
                     where status = ?
                     group by offer_id, inventory_location_id
              ) r on r.offer_id = b.offer_id and r.inventory_location_id = b.inventory_location_id
             order by b.id
        ', [ReservationStatus::Held->value]);

        $ledgerDrift = [];
        $reservedDrift = [];

        foreach ($rows as $row) {
            if ((int) $row->on_hand !== (int) $row->ledger_on_hand) {
                $ledgerDrift[] = "offer {$row->offer_id}: on_hand {$row->on_hand}, ledger says {$row->ledger_on_hand}";
            }

            if ((int) $row->reserved !== (int) $row->ledger_reserved) {
                $ledgerDrift[] = "offer {$row->offer_id}: reserved {$row->reserved}, ledger says {$row->ledger_reserved}";
            }

            if ((int) $row->reserved !== (int) $row->held) {
                $reservedDrift[] = $row;
            }
        }

        foreach ($ledgerDrift as $line) {
            $this->line('  <fg=red>ledger</> '.$line);
        }

        foreach ($reservedDrift as $row) {
            $this->line("  <fg=red>holds</> offer {$row->offer_id}: reserved {$row->reserved}, live holds total {$row->held}");
        }

        if ($ledgerDrift === [] && $reservedDrift === []) {
            $this->info(count($rows).' balance(s) reconciled; the ledger, the columns and the holds all agree.');

            return self::SUCCESS;
        }

        if ($this->option('fix') && $reservedDrift !== []) {
            foreach ($reservedDrift as $row) {
                DB::table('inventory_balances')->where('id', $row->id)->update(['reserved' => (int) $row->held]);
                $this->line("  <fg=yellow>fixed</> offer {$row->offer_id}: reserved set to {$row->held}");
            }
        }

        $this->newLine();
        $this->error('Inventory does not reconcile.');

        return self::FAILURE;
    }
}
