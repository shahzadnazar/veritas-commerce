<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\StatusRegistry;
use Illuminate\Console\Command;

/**
 * Generates the frontend status map from the PHP registry.
 *
 * Run in CI; StatusPresentationTest asserts the checked-in file matches, so a
 * status added to an enum without regenerating fails the build rather than
 * rendering an unstyled badge in production.
 */
final class ExportStatusPresentation extends Command
{
    protected $signature = 'statuses:export {--check : Fail if the generated file is out of date}';

    protected $description = 'Export the status→tone presentation map to TypeScript';

    public function handle(): int
    {
        $target = resource_path('js/design-system/generated/statuses.ts');
        $contents = self::render();

        if ($this->option('check')) {
            if (! is_file($target) || file_get_contents($target) !== $contents) {
                $this->error('resources/js/design-system/generated/statuses.ts is out of date. Run: php artisan statuses:export');

                return self::FAILURE;
            }

            $this->info('Status presentation map is up to date.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0o755, true);
        }

        file_put_contents($target, $contents);
        $this->info("Wrote {$target}");

        return self::SUCCESS;
    }

    public static function render(): string
    {
        $json = json_encode(
            StatusRegistry::presentation(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return <<<TS
        // GENERATED FILE — do not edit.
        // Source: app/Support/StatusRegistry.php  ·  Regenerate: php artisan statuses:export
        //
        // Phase 6 consistency review, finding 1: one status→tone mapping for the
        // whole product. Storefront, seller and admin all read from here.

        export type StatusTone = 'neutral' | 'pending' | 'critical' | 'inactive';

        export interface StatusPresentation {
            tone: StatusTone;
            label: string;
        }

        export const STATUS_PRESENTATION = {$json} as const satisfies Record<
            string,
            Record<string, StatusPresentation>
        >;

        export type StatusDomain = keyof typeof STATUS_PRESENTATION;

        TS;
    }
}
