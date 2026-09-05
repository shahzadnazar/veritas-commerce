<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\Catalog\Models\Product;
use App\Modules\Search\Adapters\PostgresSearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fuzzy search asks PostgreSQL a question whose answer depends on the
 * session, and this is what makes that arrangement safe.
 *
 * The adapter matches typos with the `pg_trgm` operators — `%` and `<%` —
 * rather than the `similarity()` and `word_similarity()` functions they
 * correspond to, because only the operators can be answered from the GIN
 * trigram index. Measured on the launch-scale dataset the difference was
 * a sequential scan of every search document against twenty-two index
 * pages, on every keyword search, five times per page of results.
 *
 * The price is that the operators take their cutoff from a session
 * setting instead of from the query. If a connection were ever
 * established without it, search would silently fall back to
 * PostgreSQL's default of 0.6 and return fewer typo matches — a
 * regression no customer would report and no error log would show. So
 * the setting is asserted here, and read back again by
 * `app:production-check` on the deployment itself.
 */
final class TrigramThresholdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function thresholds(): array
    {
        return [
            'whole-string similarity, used by %' => ['pg_trgm.similarity_threshold'],
            'word similarity, used by <%' => ['pg_trgm.word_similarity_threshold'],
        ];
    }

    #[Test]
    #[DataProvider('thresholds')]
    public function every_connection_carries_the_applications_own_threshold(string $setting): void
    {
        $actual = (float) DB::scalar("select current_setting('{$setting}')");

        $this->assertEqualsWithDelta(
            PostgresSearchIndex::FUZZY_THRESHOLD,
            $actual,
            0.0001,
            "{$setting} is {$actual}; the search adapter's operators would use the wrong cutoff.",
        );
    }

    /**
     * A second connection is a second session, and gets the same setting.
     *
     * The concurrency tests open one; so does every queue worker and
     * every PHP-FPM child. A listener that only fired for the first
     * connection would look correct in a single-connection test and be
     * wrong everywhere else.
     */
    #[Test]
    public function a_second_connection_is_configured_too(): void
    {
        $actual = (float) DB::connection('concurrent')
            ->scalar("select current_setting('pg_trgm.word_similarity_threshold')");

        $this->assertEqualsWithDelta(PostgresSearchIndex::FUZZY_THRESHOLD, $actual, 0.0001);
    }

    /**
     * The operator and the function it replaced still agree.
     *
     * This is the equivalence the change rests on: `a <% b` at threshold
     * t returns what `word_similarity(a, b) > t` returned. Asserted over
     * the search documents themselves rather than over a pair of literals,
     * so it is a claim about data rather than about arithmetic.
     */
    #[Test]
    public function the_operator_selects_what_the_function_selected(): void
    {
        $this->seedTitles(['stainless steel kettle', 'walnut dining chair', 'titanium camp lantern']);

        foreach (['kettel', 'walnut chiar', 'titanium', 'nothing like these words'] as $phrase) {
            $byOperator = DB::table('product_search_documents')
                ->whereRaw('? <% normalised_title', [$phrase])
                ->orderBy('product_id')
                ->pluck('product_id')
                ->all();

            $byFunction = DB::table('product_search_documents')
                ->whereRaw('word_similarity(?, normalised_title) > ?', [$phrase, PostgresSearchIndex::FUZZY_THRESHOLD])
                ->orderBy('product_id')
                ->pluck('product_id')
                ->all();

            $this->assertSame($byFunction, $byOperator, "The two forms disagree on \"{$phrase}\".");
        }
    }

    /** @param array<int, string> $titles */
    private function seedTitles(array $titles): void
    {
        foreach ($titles as $title) {
            $product = Product::factory()->createOne(['title' => $title]);

            DB::table('product_search_documents')->insert([
                'product_id' => $product->id,
                'title' => $title,
                'searchable_text' => $title,
                'normalised_title' => $title,
                'is_public' => true,
                'indexed_at' => now(),
            ]);
        }
    }
}
