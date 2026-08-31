<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function a_split_always_sums_back_to_the_original(): void
    {
        // Exhaustive over a range that crosses every rounding boundary.
        foreach (range(0, 999) as $minor) {
            foreach (['0.00', '1.50', '8.00', '12.00', '12.35', '20.00', '30.00'] as $rate) {
                [$taken, $left] = Money::of($minor)->splitPercentage($rate);

                $this->assertSame(
                    $minor,
                    $taken->minor + $left->minor,
                    "Split of {$minor} at {$rate}% did not sum back.",
                );
            }
        }
    }

    #[Test]
    #[DataProvider('splits')]
    public function it_splits_a_percentage_half_up(int $minor, string $rate, int $expectedCommission): void
    {
        [$commission, $earning] = Money::of($minor)->splitPercentage($rate);

        $this->assertSame($expectedCommission, $commission->minor);
        $this->assertSame($minor - $expectedCommission, $earning->minor);
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function splits(): iterable
    {
        yield 'the prototype order' => [32_800, '12.00', 3_936];
        yield 'a round hundred' => [10_000, '12.00', 1_200];
        yield 'zero rate takes nothing' => [10_000, '0.00', 0];
        yield 'exact half rounds up' => [50, '1.00', 1];       // 0.5 -> 1
        yield 'below half rounds down' => [49, '1.00', 0];     // 0.49 -> 0
        yield 'one cent at 12%' => [1, '12.00', 0];
        yield 'large order' => [1_000_003, '12.35', 123_500];
    }

    #[Test]
    public function a_rate_must_be_a_decimal_string_not_a_float(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('decimal string');

        Money::of(1000)->splitPercentage('12.000001');
    }

    #[Test]
    public function money_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        Money::of(-1);
    }

    #[Test]
    public function currencies_cannot_be_mixed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency mismatch');

        Money::of(100, 'USD')->plus(Money::of(100, 'EUR'));
    }

    #[Test]
    public function it_formats_for_display(): void
    {
        $this->assertSame('$328.00', Money::of(32_800)->format());
        $this->assertSame('$0.05', Money::of(5)->format());
        $this->assertSame('$1,000.00', Money::of(100_000)->format());
    }

    #[Test]
    public function arithmetic_stays_in_integers(): void
    {
        $total = Money::of(30_800)->plus(Money::of(600))->plus(Money::of(1_400));

        $this->assertSame(32_800, $total->minor);
        $this->assertSame(65_600, $total->times(2)->minor);
        $this->assertSame(30_800, $total->minus(Money::of(2_000))->minor);
    }
}
