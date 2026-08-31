<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Implemented by every status enum that reaches the UI.
 *
 * StatusPresentationTest enumerates the implementors and asserts each case
 * returns a tone and a label, so a status added after handoff cannot ship
 * without a presentation.
 */
interface HasStatusTone
{
    public function tone(): StatusTone;

    public function label(): string;
}
