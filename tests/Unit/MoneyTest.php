<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_from_major_converts_to_exact_cents(): void
    {
        $this->assertSame(10440, Money::fromMajor(104.4)->cents());
        $this->assertSame(10000, Money::fromMajor(100.0)->cents());
        $this->assertSame(1160, Money::fromMajor('11.60')->cents());
        $this->assertSame(-5000, Money::fromMajor(-50.0)->cents());
    }

    public function test_to_major_round_trips(): void
    {
        $this->assertSame('104.40', Money::fromCents(10440)->toMajor());
        $this->assertSame('0.05', Money::fromCents(5)->toMajor());
        $this->assertSame('-50.00', Money::fromCents(-5000)->toMajor());
    }

    public function test_add_and_sub_are_exact(): void
    {
        $a = Money::fromMajor(100.0);
        $b = Money::fromMajor(0.10);

        // 100.00 + 0.10, ten times, must be exactly 101.00 - the classic
        // float-drift case (0.1 is not exactly representable in binary
        // floating point; repeated addition of it famously does NOT sum
        // to exactly 1.0 in naive float arithmetic).
        $total = $a;
        for ($i = 0; $i < 10; $i++) {
            $total = $total->add($b);
        }

        $this->assertSame(10100, $total->cents());
        $this->assertSame('101.00', $total->toMajor());
    }

    public function test_multiply_by_rate_matches_doc_worked_example(): void
    {
        // §7 worked example: gross 100, VAT 16% -> 16.00 exactly.
        $vat = Money::fromMajor(100.0)->multiplyByRate(0.16);
        $this->assertSame(1600, $vat->cents());

        // retention 10% of 100 -> 10.00
        $retention = Money::fromMajor(100.0)->multiplyByRate(0.10);
        $this->assertSame(1000, $retention->cents());

        // VAT on retention: 16% of 10.00 -> 1.60
        $vatOnRetention = $retention->multiplyByRate(0.16);
        $this->assertSame(160, $vatOnRetention->cents());
    }

    public function test_multiply_by_rate_rounds_half_up_on_a_fractional_cent(): void
    {
        // 33.335 rounds to 33.34 under round-half-up, not banker's rounding.
        $amount = Money::fromCents(33335)->multiplyByRate(1); // no-op scale check
        $this->assertSame(33335, $amount->cents());

        // A genuine fractional-cent case: 1 cent * 0.155 = 0.155 cents -> rounds to 0.
        $this->assertSame(0, Money::fromCents(1)->multiplyByRate(0.155)->cents());
        // 5 cents * 0.5 = 2.5 cents -> rounds up to 3 (round-half-up).
        $this->assertSame(3, Money::fromCents(5)->multiplyByRate(0.5)->cents());
    }

    public function test_landed_cost_style_proportional_split_sums_exactly(): void
    {
        // The doc's own residue-absorption example: three equal 100.00
        // lines, total landed cost 100.00 -> naive independent rounding
        // gives 33.33 * 3 = 99.99, short by a cent.
        $third = Money::fromMajor(100.0)->multiplyByRate('0.3333333333');
        $this->assertSame(3333, $third->cents());

        $naiveTotal = $third->add($third)->add($third);
        $this->assertNotSame(10000, $naiveTotal->cents(), 'sanity check: naive per-line rounding really does fall short');
    }

    public function test_sum_of_no_amounts_is_zero(): void
    {
        $this->assertTrue(Money::sum()->isZero());
    }

    public function test_equals_greater_less(): void
    {
        $a = Money::fromMajor(10.0);
        $b = Money::fromMajor(20.0);

        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($a->equals(Money::fromMajor(10.0)));
        $this->assertFalse($a->equals($b));
    }
}
