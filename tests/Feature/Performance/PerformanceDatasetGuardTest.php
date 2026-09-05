<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Support\Performance\PerformanceDataset;
use App\Support\Performance\PerformanceScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scale seeder is only allowed to run where it is meant to.
 *
 * These tests are unusual in that the thing they most want to check —
 * that the generator builds a coherent launch-sized database — is the one
 * thing they must not do here: the suite's database is precisely the
 * database the command refuses, and six hundred thousand rows would make
 * every other test in the suite slower for no benefit. So the build is
 * exercised by hand against a scratch database, and what is pinned here
 * is the part that has to hold without anybody watching: that the command
 * refuses, that the refusal leaves the database untouched, and that the
 * arithmetic the generated SQL depends on cannot silently stop holding.
 */
final class PerformanceDatasetGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_the_database_phpunit_is_configured_to_use(): void
    {
        DB::table('brands')->insert([
            'public_id' => str_pad('GUARD', 26, '0'),
            'name' => 'Survivor',
            'slug' => 'survivor',
            'normalised_name' => 'survivor',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runArtisan('veritas:seed-performance', ['--force' => true])
            ->expectsOutputToContain('phpunit.xml')
            ->assertExitCode(1)
            ->run();

        // The point of the refusal: it happens before anything is emptied.
        $this->assertDatabaseHas('brands', ['slug' => 'survivor']);
    }

    #[Test]
    public function it_refuses_a_protected_database(): void
    {
        config(['veritas.database.protected' => [config('database.connections.pgsql.database')]]);

        $this->runArtisan('veritas:seed-performance', ['--force' => true])
            ->expectsOutputToContain('VERITAS_PROTECTED_DATABASES')
            ->assertExitCode(1)
            ->run();
    }

    #[Test]
    public function it_refuses_a_production_environment(): void
    {
        config(['app.env' => 'production']);

        $this->runArtisan('veritas:seed-performance', ['--force' => true])
            ->expectsOutputToContain('does not run here')
            ->assertExitCode(1)
            ->run();
    }

    /**
     * The production refusal outranks the others.
     *
     * Not cosmetic: an operator who has just been told their database is
     * protected will reach for `VERITAS_PROTECTED_DATABASES=`, and on a
     * production box that is the wrong instinct to encourage.
     */
    #[Test]
    public function the_production_refusal_is_the_one_reported_first(): void
    {
        config([
            'app.env' => 'production',
            'veritas.database.protected' => [config('database.connections.pgsql.database')],
        ]);

        $this->runArtisan('veritas:seed-performance', ['--force' => true])
            ->expectsOutputToContain('does not run here')
            ->assertExitCode(1)
            ->run();
    }

    #[Test]
    public function it_rejects_a_scale_profile_it_does_not_know(): void
    {
        $this->runArtisan('veritas:seed-performance', ['--scale' => 'enormous', '--force' => true])
            ->expectsOutputToContain('Use phase1 or small')
            ->assertExitCode(1)
            ->run();
    }

    #[Test]
    public function an_ordinary_database_carries_no_generated_rows(): void
    {
        $this->assertSame([], PerformanceDataset::contamination());
    }

    #[Test]
    public function the_marker_fits_the_columns_it_is_written_into(): void
    {
        // `public_id` is char(26); a shorter value would be space-padded
        // and stop starting with the marker on the way back out.
        $identifier = PerformanceDataset::MARKER.str_repeat('0', 21);

        $this->assertSame(26, strlen($identifier));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]+$/', $identifier, 'ULIDs are Crockford base32.');
    }

    /**
     * The offer generator's arithmetic, checked rather than trusted.
     *
     * Each of these is a unique-index violation waiting to happen if a
     * future profile changes one number without the others. They fail in
     * the middle of a twenty-eight-second build, which is exactly the
     * kind of failure worth catching in a millisecond instead.
     */
    #[Test]
    #[DataProvider('profiles')]
    public function every_scale_profile_can_generate_distinct_offers(string $name): void
    {
        $scale = PerformanceScale::named($name);
        $band = $scale->products - $scale->hotProducts();

        $this->assertLessThanOrEqual(
            $scale->hotProducts(),
            $scale->hotOffersPerSeller(),
            'A seller cannot list more contested products than there are.',
        );

        $this->assertTrue(
            PerformanceScale::coprime($scale->coldStep(), $band),
            'The stride must be coprime to the uncontested band, or a seller wraps onto a product it already lists.',
        );

        $this->assertLessThanOrEqual(
            $band,
            $scale->largeSellerOffers() * 2,
            'The largest catalogue, jitter included, must still fit inside the uncontested band.',
        );

        $this->assertGreaterThanOrEqual(
            $scale->largeSellers() + $scale->mediumSellers(),
            $scale->sellers,
            'The tiers must fit inside the seller population.',
        );

        $this->assertGreaterThan(
            $scale->smallSellerOffers(),
            $scale->largeSellerOffers(),
            'Without a spread between the tiers the statistics are flat and the plans are meaningless.',
        );
    }

    #[Test]
    public function an_unknown_profile_is_refused_at_the_source(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PerformanceScale::named('enormous');
    }

    /** @return array<int, array{0: string}> */
    public static function profiles(): array
    {
        return array_map(static fn (string $name): array => [$name], PerformanceScale::names());
    }
}
