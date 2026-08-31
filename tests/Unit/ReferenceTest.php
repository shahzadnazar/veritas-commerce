<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Reference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Decision 1, settled: one customer-facing number with a two-digit
 * per-seller sequence. Not alphabetic suffixes.
 */
final class ReferenceTest extends TestCase
{
    #[Test]
    public function a_marketplace_order_reads_v_c_number(): void
    {
        $this->assertSame('VC-24081', Reference::order(24081));
    }

    #[Test]
    public function seller_sub_orders_are_zero_padded_two_digit_sequences(): void
    {
        $order = Reference::order(24081);

        $this->assertSame('VC-24081-01', Reference::subOrder($order, 1));
        $this->assertSame('VC-24081-02', Reference::subOrder($order, 2));
        $this->assertSame('VC-24081-03', Reference::subOrder($order, 3));
        $this->assertSame('VC-24081-10', Reference::subOrder($order, 10));
    }

    #[Test]
    public function a_sub_order_names_its_parent(): void
    {
        $this->assertSame('VC-24081', Reference::parentOf('VC-24081-02'));
    }

    #[Test]
    public function other_references_carry_their_own_prefixes(): void
    {
        $this->assertSame('APP-1180', Reference::application(1180));
        $this->assertSame('PO-2044', Reference::payout(2044));
        $this->assertSame('RF-91', Reference::refund(91));
    }

    #[Test]
    public function a_position_beyond_two_digits_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reference::subOrder('VC-24081', 100);
    }

    #[Test]
    public function a_zero_position_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reference::subOrder('VC-24081', 0);
    }
}
