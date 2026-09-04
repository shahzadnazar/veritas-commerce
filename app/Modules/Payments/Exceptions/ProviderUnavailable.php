<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The provider could not be reached, or answered with something the
 * platform cannot act on.
 *
 * Deliberately distinct from a decline. A declined card is an answer; this
 * is the absence of one, and the two call for opposite handling — §66: an
 * outage must leave the order payable and the customer's stock held, where
 * a decline is recorded against the attempt.
 */
final class ProviderUnavailable extends RuntimeException {}
