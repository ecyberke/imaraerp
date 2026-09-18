<?php

namespace App\Services\Ledger;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure, unpersisted stand-in for a JournalLine - mirrors
 * LedgerPostingServiceTest.php's own JournalLine value class exactly
 * (same validation), except accounts are referenced by name (string,
 * resolved to a real ChartOfAccount at commit time) and amounts are
 * Money, never float.
 */
final class LineInput
{
    public readonly Money $debit;

    public readonly Money $credit;

    public function __construct(
        public readonly string $account,
        ?Money $debit = null,
        ?Money $credit = null,
        public readonly ?string $analyticAccountCode = null,
        public readonly ?string $taxCode = null,
    ) {
        $this->debit = $debit ?? Money::zero();
        $this->credit = $credit ?? Money::zero();

        if ($this->debit->isPositive() && $this->credit->isPositive()) {
            throw new InvalidArgumentException('A journal line cannot carry both a debit and a credit.');
        }
        if ($this->debit->isNegative() || $this->credit->isNegative()) {
            throw new InvalidArgumentException('Debit/credit amounts must be non-negative.');
        }
    }
}
