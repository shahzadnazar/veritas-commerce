<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;

/**
 * Implemented by every enum that is a workflow state rather than a plain label.
 *
 * Transitions live on the enum itself so there is exactly one answer to
 * "can this move there?", and StateMachineTest walks every case.
 */
interface StatusTransitions
{
    /** @return array<int, static> the states this one may move to */
    public function allowedTransitions(): array;

    public function isTerminal(): bool;
}
