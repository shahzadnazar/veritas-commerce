<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * An inbound event did not carry a valid signature for the configured
 * secret.
 *
 * It is not from the provider, whatever it claims to be. Nothing is
 * recorded, nothing is processed, and the response says only that the
 * request was rejected — an error naming which check failed would help
 * somebody iterate toward passing it.
 */
final class ProviderSignatureInvalid extends RuntimeException {}
