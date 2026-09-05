<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A state machine whose records do not all start in the same place.
 *
 * Most do: an order begins as a draft, a shipment as a draft, a payout as
 * requested, and StateMachineTest can assume the first case is the entry
 * point and require every other state to have an inbound edge.
 *
 * The seller ledger is not like that, and pretending otherwise would hide
 * the reason. A ledger entry is created in whichever state its money is
 * already in — an earning before delivery starts PENDING, a refund
 * reversal against spendable money starts AVAILABLE, the debit a settled
 * payout appends starts PAID and never moves again. None of those is
 * reached by transition, because none of them is a stage that something
 * passed through.
 *
 * Declaring the entry points keeps the reachability invariant strict for
 * every state that genuinely is reached, instead of relaxing it for the
 * whole enum.
 */
interface HasEntryStates
{
    /** @return array<int, static> states a record may be created in */
    public static function entryStates(): array;
}
