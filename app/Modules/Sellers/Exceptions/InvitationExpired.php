<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Exceptions;

use RuntimeException;

/**
 * Raised when a redemption is refused because the invitation has lapsed.
 *
 * A distinct type because the refusal has a side effect the refusal itself
 * rolls back: the invitation has to be *recorded* as expired, and that
 * write has to happen outside the transaction that rejected it.
 */
final class InvitationExpired extends RuntimeException {}
