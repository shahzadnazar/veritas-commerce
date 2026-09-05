<?php

declare(strict_types=1);

/**
 * Render the cross-tenant access matrix from the probes that run it.
 *
 * Generated rather than written, for the reason every security document
 * that is written by hand eventually stops being true: the table below is
 * the test suite's own data, so a probe that is added, removed or renamed
 * changes the document or fails the check.
 *
 *   php tools/idor-matrix.php            write docs/security/idor-matrix.md
 *   php tools/idor-matrix.php --check    fail if the file is out of date
 */

require __DIR__.'/../vendor/autoload.php';

use Tests\Feature\Security\CrossTenantAccessMatrixTest;

$target = __DIR__.'/../docs/security/idor-matrix.md';
$rendered = CrossTenantAccessMatrixTest::renderMatrix();

if (in_array('--check', $argv, true)) {
    $current = is_readable($target) ? (string) file_get_contents($target) : '';

    if ($current !== $rendered) {
        fwrite(STDERR, "docs/security/idor-matrix.md is out of date. Run: php tools/idor-matrix.php\n");
        exit(1);
    }

    fwrite(STDOUT, "idor-matrix: up to date\n");
    exit(0);
}

file_put_contents($target, $rendered);
fwrite(STDOUT, "idor-matrix: written to docs/security/idor-matrix.md\n");
