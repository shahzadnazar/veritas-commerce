<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/**
 * A stock operation that would leave the ledger in a state it may not be
 * in — negative stock, a hold released twice, a reservation promising
 * units nobody has.
 *
 * Its message is written to be shown to the person who attempted it.
 */
final class InvalidStockOperation extends RuntimeException {}
