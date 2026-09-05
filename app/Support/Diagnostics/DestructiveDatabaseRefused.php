<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

use RuntimeException;

/**
 * A destructive command was stopped before it touched anything.
 *
 * Its own type rather than a bare RuntimeException so tests can assert
 * that the guard refused, rather than matching on the wording of the
 * refusal — the wording is meant to be improved over time, the refusal
 * is not.
 */
final class DestructiveDatabaseRefused extends RuntimeException {}
