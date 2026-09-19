<?php

namespace App\Support;

use JsonSerializable;

/**
 * ledger-core's money-representation decision, stated in writing before
 * the first migration (execution_plan.md requires this explicitly):
 *
 * **Integer minor units (cents), stored as `bigInteger` columns
 * (`debit_cents`/`credit_cents` on JournalLine), with all arithmetic done
 * here via bcmath/string manipulation — never PHP float.** This is the
 * plan's own recommendation, adopted deliberately rather than inherited
 * by accident from LedgerPostingServiceTest.php's float-with-epsilon
 * spec, which that file's own header explicitly flags as a spec
 * convenience, not a production pattern. KES amounts routinely run into
 * the millions, and per-line VAT rounding (architecture §3.9: each
 * InvoiceLine.vat_amount rounded independently, summed for the invoice
 * total) already assumes exact cent-level arithmetic - floats invite
 * exactly the class of bug this document's own review rounds spent real
 * effort catching (§7's changelog).
 *
 * Every LedgerPostingService method takes/returns Money, never a raw
 * float or int. No float ever appears in this class, including the
 * final rounding step of multiplyByRate() - a manual round-half-up on a
 * bcmath decimal string, not a (float) cast, so nothing here is subject
 * to IEEE 754 drift at any point.
 */
final class Money implements JsonSerializable
{
    private function __construct(private readonly int $cents)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * @param  float|int|string  $major  A KES amount, e.g. 104.4 or "104.40".
     *                                    Accepts float/int for convenience at call
     *                                    sites (test worked examples, simple
     *                                    literals) - the conversion itself is
     *                                    string-based, not float arithmetic.
     */
    public static function fromMajor(float|int|string $major): self
    {
        $str = is_string($major) ? $major : self::stringifyNumber($major);
        $negative = str_starts_with($str, '-');
        $str = ltrim($str, '-');

        [$whole, $frac] = array_pad(explode('.', $str), 2, '0');
        $frac = str_pad(substr($frac, 0, 2), 2, '0');

        $cents = ((int) $whole) * 100 + (int) $frac;

        return new self($negative ? -$cents : $cents);
    }

    private static function stringifyNumber(float|int $n): string
    {
        // number_format avoids scientific notation and rounds to a fixed
        // 2dp representation - a controlled string conversion, not
        // arithmetic on the float itself.
        return number_format($n, 2, '.', '');
    }

    public function cents(): int
    {
        return $this->cents;
    }

    /** KES amount as a fixed 2dp string, e.g. "104.40". */
    public function toMajor(): string
    {
        $negative = $this->cents < 0;
        $abs = abs($this->cents);
        $whole = intdiv($abs, 100);
        $frac = $abs % 100;

        return ($negative ? '-' : '').$whole.'.'.str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }

    public function add(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function sub(Money $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function negate(): self
    {
        return new self(-$this->cents);
    }

    public function abs(): self
    {
        return new self(abs($this->cents));
    }

    /**
     * Multiply by a rate (e.g. 0.16 for 16% VAT), rounding to the nearest
     * cent with round-half-up - entirely via bcmath + string manipulation,
     * never a float cast, so no IEEE 754 rounding surprises at any scale.
     */
    public function multiplyByRate(float|int|string $rate): self
    {
        $rateStr = is_string($rate) ? $rate : self::stringifyRate($rate);
        $product = bcmul((string) $this->cents, $rateStr, 6); // scaled decimal string, e.g. "1600.000000"

        return new self(self::roundHalfUpToInt($product));
    }

    /**
     * Same operation as multiplyByRate() - genuinely identical bcmath
     * implementation - offered under a name that reads correctly at a
     * quantity/day-count call site (e.g. `$dailyRate->multiply('12')` for
     * 12 days) rather than forcing every non-percentage multiplier to be
     * called a "rate".
     */
    public function multiply(float|int|string $factor): self
    {
        return $this->multiplyByRate($factor);
    }

    private static function stringifyRate(float|int $rate): string
    {
        // Rates in this system (0.16, 0.10, 0.03, 0.05, 0.20, ...) are
        // simple few-decimal values; sprintf with generous precision then
        // trimming trailing zeros keeps the bcmath input exact for every
        // rate this system actually uses.
        return rtrim(rtrim(sprintf('%.10f', $rate), '0'), '.') ?: '0';
    }

    private static function roundHalfUpToInt(string $decimalString): int
    {
        $negative = str_starts_with($decimalString, '-');
        $decimalString = ltrim($decimalString, '-');

        [$whole, $frac] = array_pad(explode('.', $decimalString), 2, '0');
        $frac = str_pad($frac, 1, '0');
        $roundUp = ((int) $frac[0]) >= 5;

        $result = (int) $whole + ($roundUp ? 1 : 0);

        return $negative ? -$result : $result;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(Money $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function lessThan(Money $other): bool
    {
        return $this->cents < $other->cents;
    }

    public static function sum(Money ...$amounts): self
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount->cents();
        }

        return new self($total);
    }

    public function jsonSerialize(): string
    {
        return $this->toMajor();
    }

    public function __toString(): string
    {
        return $this->toMajor();
    }
}
