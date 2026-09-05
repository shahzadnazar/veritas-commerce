<?php

declare(strict_types=1);

/**
 * Render the failure matrix from the drills that prove it.
 *
 * Written the same way as the IDOR matrix and for the same reason: a
 * failure matrix maintained by hand is a document that describes what
 * somebody believed the system did on the day they wrote it. This one is
 * generated from the drill classes themselves, so a scenario that is
 * added, removed or renamed changes the document or fails the check.
 *
 *   php tools/failure-matrix.php          write docs/operations/failure-matrix.md
 *   php tools/failure-matrix.php --check  fail if the file is out of date
 */

require __DIR__.'/../vendor/autoload.php';

use Tests\Support\Failure\FailureMatrix;

$target = __DIR__.'/../docs/operations/failure-matrix.md';
$rendered = FailureMatrix::render();

if (in_array('--check', $argv, true)) {
    $current = is_readable($target) ? (string) file_get_contents($target) : '';

    if ($current !== $rendered) {
        fwrite(STDERR, "docs/operations/failure-matrix.md is out of date. Run: php tools/failure-matrix.php\n");
        exit(1);
    }

    fwrite(STDOUT, "failure-matrix: up to date\n");
    exit(0);
}

file_put_contents($target, $rendered);
fwrite(STDOUT, "failure-matrix: written to docs/operations/failure-matrix.md\n");
