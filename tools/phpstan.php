<?php

declare(strict_types=1);

/*
 * Runs PHPStan through whichever binary is available.
 *
 * Prefers vendor/bin/phpstan (the Composer install, which also brings
 * Larastan's Laravel extension). Falls back to the pinned phar downloaded
 * by tools/install-phpstan.php where Composer cannot reach the package.
 */

$root = dirname(__DIR__);
$vendor = $root.'/vendor/bin/phpstan';
$phar = __DIR__.'/phpstan.phar';

$binary = is_file($vendor) ? $vendor : $phar;

if (! is_file($binary)) {
    fwrite(STDERR, "No PHPStan binary. Run: composer install  (or: composer phpstan:install)\n");
    exit(1);
}

$arguments = array_slice($argv, 1);

if ($arguments === []) {
    $arguments = ['analyse'];
}

// Larastan is what makes Laravel's dynamic behaviour analysable. Use the
// configuration that includes it whenever it is installed.
$analysing = $arguments === [] || in_array($arguments[0], ['analyse', 'analyze'], true);
$hasLarastan = is_dir($root.'/vendor/larastan/larastan');

if ($analysing && ! in_array('-c', $arguments, true) && ! in_array('--configuration', $arguments, true)) {
    $arguments[] = '-c';
    $arguments[] = $hasLarastan ? 'phpstan.larastan.neon' : 'phpstan.neon';
}

$command = array_merge([PHP_BINARY, $binary], $arguments);

fwrite(STDOUT, sprintf(
    "PHPStan: %s, Larastan: %s\n",
    str_ends_with($binary, '.phar') ? 'pinned phar' : 'composer install',
    $hasLarastan ? 'yes' : 'NOT INSTALLED (Laravel-aware rules unavailable)',
));

$process = proc_open(
    array_map('strval', $command),
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
);

exit(is_resource($process) ? proc_close($process) : 1);
