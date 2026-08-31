<?php

declare(strict_types=1);

/*
 * Generates @property annotations on Eloquent models from the live schema.
 *
 * Larastan reads model properties by reflecting the database; where it is
 * unavailable, real annotations give PHPStan the same information — and
 * they document the model for anyone reading it, which reflection does not.
 *
 * The annotations are generated from PostgreSQL's catalog plus each model's
 * casts(), so they cannot drift from the migrations by hand. Re-run after a
 * schema change:  php tools/annotate-models.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array<string, string> column => PHP type */
function columnTypes(string $table): array
{
    $rows = DB::select(
        'select column_name, data_type, is_nullable
         from information_schema.columns
         where table_schema = ? and table_name = ?
         order by ordinal_position',
        ['public', $table],
    );

    $map = [];

    foreach ($rows as $row) {
        $type = match ($row->data_type) {
            'bigint', 'integer', 'smallint' => 'int',
            'boolean' => 'bool',
            'numeric', 'double precision', 'real' => 'string',
            'timestamp with time zone', 'timestamp without time zone', 'date' => '\Illuminate\Support\Carbon',
            'jsonb', 'json' => 'array<string, mixed>',
            default => 'string',
        };

        if ($row->is_nullable === 'YES') {
            $type = str_starts_with($type, 'array') ? "{$type}|null" : "{$type}|null";
        }

        $map[$row->column_name] = $type;
    }

    return $map;
}

$models = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../app/Modules')) as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Models/')) {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    preg_match('/namespace\s+([^;]+);/', $contents, $ns);
    $class = trim($ns[1]).'\\'.$file->getBasename('.php');

    if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
        continue;
    }

    $models[$class] = $file->getPathname();
}

ksort($models);
$updated = 0;

foreach ($models as $class => $path) {
    /** @var Model $instance */
    $instance = new $class;
    $columns = columnTypes($instance->getTable());

    if ($columns === []) {
        fwrite(STDERR, "No columns for {$class} ({$instance->getTable()})\n");

        continue;
    }

    // A cast wins over the raw column type: an enum column is its enum, a
    // datetime is Carbon, an encrypted string is still a string.
    $casts = $instance->getCasts();
    $lines = [];

    foreach ($columns as $column => $type) {
        if (isset($casts[$column])) {
            $cast = $casts[$column];
            $nullable = str_ends_with($type, '|null');
            $type = match (true) {
                $cast === 'datetime' => '\Illuminate\Support\Carbon|null',
                $cast === 'array' => 'array<string, mixed>|null',
                $cast === 'boolean' => 'bool',
                $cast === 'integer' => 'int',
                $cast === 'encrypted' => 'string',
                $cast === 'hashed' => 'string',
                enum_exists($cast) => '\\'.ltrim($cast, '\\'),
                default => $type,
            };

            // The cast decides the shape; the column decides whether it can
            // be absent. Losing that is how a nullable price ends up typed
            // as always-present.
            if ($nullable && ! str_ends_with($type, '|null')) {
                $type .= '|null';
            }
        }

        $lines[] = " * @property {$type} \${$column}";
    }

    // Relations read as properties ($membership->sellerAccount), which is
    // invisible to static analysis without an annotation.
    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType) {
            continue;
        }

        $relationClass = $returnType->getName();

        if (! is_a($relationClass, Relation::class, true)) {
            continue;
        }

        $doc = (string) $method->getDocComment();

        if (! preg_match('/@return\s+\S+<([^,>]+)/', $doc, $related)) {
            continue;
        }

        $related = trim($related[1]);
        $many = is_a($relationClass, HasMany::class, true)
            || is_a($relationClass, BelongsToMany::class, true);

        $type = $many
            ? '\\Illuminate\\Database\\Eloquent\\Collection<int, '.$related.'>'
            : $related.'|null';

        $lines[] = ' * @property-read '.$type.' $'.$method->getName();
    }

    $block = implode("\n", $lines);

    $source = (string) file_get_contents($path);
    $marker = ' * Columns, generated by tools/annotate-models.php from the schema.';

    // Drop any previously generated lines so re-running is idempotent.
    $source = (string) preg_replace(
        '#\n \*\n'.preg_quote($marker, '#').'\n(?: \* @property.*\n)+#',
        "\n",
        $source,
    );

    // Merge into the class's own docblock rather than stacking a second one:
    // the description and the columns belong together.
    $classLine = 'final class '.class_basename($class);
    $pattern = '#(/\*\*\n(?:.*\n)*? \*/\n)('.preg_quote($classLine, '#').')#';

    if (preg_match($pattern, $source)) {
        $source = (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($marker, $block): string {
                $doc = rtrim(substr($m[1], 0, strrpos($m[1], ' */')));

                return $doc."\n *\n".$marker."\n".$block."\n */\n".$m[2];
            },
            $source,
            1,
        );
    } else {
        $source = (string) preg_replace(
            '/^('.preg_quote($classLine, '/').')/m',
            "/**\n".$marker."\n".$block."\n */\n$1",
            $source,
            1,
        );
    }

    file_put_contents($path, $source);
    $updated++;
}

fwrite(STDOUT, "Annotated {$updated} models.\n");
