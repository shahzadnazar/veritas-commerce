<?php

declare(strict_types=1);

/*
 * Downloads the pinned PHPStan phar.
 *
 * Why this exists: this development environment's egress policy blocks
 * codeload.github.com and the GitHub archive API, which is where Composer
 * fetches phpstan/phpstan's dist from — so `composer install` cannot place
 * vendor/bin/phpstan here. GitHub *release assets* are reachable, and the
 * phar is published as one.
 *
 * CI runners have unrestricted egress and install phpstan and larastan
 * through Composer normally; tools/phpstan.php prefers vendor/bin/phpstan
 * whenever it exists, so this path is a local fallback and nothing more.
 */

const PHPSTAN_VERSION = '2.2.12';
const PHPSTAN_URL = 'https://github.com/phpstan/phpstan/releases/download/'.PHPSTAN_VERSION.'/phpstan.phar';

$target = __DIR__.'/phpstan.phar';

if (is_file($target)) {
    $current = trim((string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($target).' --version 2>&1'));

    if (str_contains($current, PHPSTAN_VERSION)) {
        fwrite(STDOUT, 'PHPStan '.PHPSTAN_VERSION." already present.\n");
        exit(0);
    }
}

fwrite(STDOUT, 'Downloading PHPStan '.PHPSTAN_VERSION."…\n");

$stream = @fopen(PHPSTAN_URL, 'rb');

if ($stream === false) {
    fwrite(STDERR, 'Could not reach '.PHPSTAN_URL."\n");
    fwrite(STDERR, "Install through Composer instead: composer install\n");
    exit(1);
}

$bytes = file_put_contents($target, $stream);
fclose($stream);

if ($bytes === false || $bytes < 1_000_000) {
    @unlink($target);
    fwrite(STDERR, 'Download looked wrong ('.var_export($bytes, true)." bytes).\n");
    exit(1);
}

chmod($target, 0o755);
fwrite(STDOUT, "Wrote {$target} ({$bytes} bytes).\n");
