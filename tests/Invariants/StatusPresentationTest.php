<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Console\Commands\ExportStatusPresentation;
use App\Support\HasStatusTone;
use App\Support\StatusRegistry;
use App\Support\StatusTone;
use BackedEnum;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Invariant 8 — every status the UI can render has exactly one shared
 * presentation mapping.
 *
 * Phase 6 of the design review found the status-to-tone map duplicated
 * three times, once per application, and warned that the first status added
 * after handoff would only be added to one of them. These tests are what
 * make that impossible: the mapping lives in PHP, the TypeScript is
 * generated from it, and the generated file is verified in CI.
 */
final class StatusPresentationTest extends TestCase
{
    #[Test]
    public function every_registered_enum_case_has_a_tone_and_a_label(): void
    {
        foreach (StatusRegistry::map() as $domain => $enum) {
            $this->assertContains(
                HasStatusTone::class,
                class_implements($enum) ?: [],
                "{$enum} is registered for the UI but does not implement HasStatusTone.",
            );

            foreach ($enum::cases() as $case) {
                /** @var HasStatusTone&BackedEnum $case */
                $this->assertInstanceOf(
                    StatusTone::class,
                    $case->tone(),
                    "{$domain}.{$case->value} has no tone.",
                );

                $this->assertNotSame(
                    '',
                    trim($case->label()),
                    "{$domain}.{$case->value} has no label.",
                );
            }
        }
    }

    #[Test]
    public function only_the_four_documented_tones_are_used(): void
    {
        $allowed = array_map(static fn (StatusTone $t): string => $t->value, StatusTone::cases());

        $this->assertSame(['neutral', 'pending', 'critical', 'inactive'], $allowed);

        foreach (StatusRegistry::presentation() as $domain => $cases) {
            foreach ($cases as $value => $presentation) {
                $this->assertContains(
                    $presentation['tone'],
                    $allowed,
                    "{$domain}.{$value} uses a tone outside the mono system.",
                );
            }
        }
    }

    #[Test]
    public function the_generated_typescript_matches_the_php_registry(): void
    {
        $path = resource_path('js/design-system/generated/statuses.ts');

        $this->assertFileExists($path, 'Run: php artisan statuses:export');

        $this->assertSame(
            ExportStatusPresentation::render(),
            file_get_contents($path),
            'statuses.ts is stale. A status was added without regenerating: php artisan statuses:export',
        );
    }

    #[Test]
    public function the_registry_covers_every_status_enum_in_the_codebase(): void
    {
        $registered = array_values(StatusRegistry::map());
        $found = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Modules'))
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains($file->getPathname(), '/Enums/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'implements HasStatusTone')
                && ! str_contains($contents, 'HasStatusTone,')) {
                continue;
            }

            preg_match('/namespace\s+([^;]+);/', $contents, $ns);
            $class = trim($ns[1]).'\\'.$file->getBasename('.php');
            $found[] = $class;
        }

        sort($registered);
        sort($found);

        $this->assertSame(
            $found,
            $registered,
            'An enum implements HasStatusTone but is missing from StatusRegistry, so the UI cannot render it.',
        );
    }
}
