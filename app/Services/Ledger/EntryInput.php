<?php

namespace App\Services\Ledger;

use App\Support\Money;

/**
 * Pure, unpersisted stand-in for a JournalEntry - mirrors
 * LedgerPostingServiceTest.php's JournalEntry value class. Every
 * postXxx() method on LedgerPostingService returns one of these; nothing
 * touches the database until LedgerPostingService::commit() persists it.
 * Keeping the arithmetic layer pure like this is what lets postXxx()'s
 * signatures and bodies stay a close, line-for-line port of the
 * reference file (Money instead of float, account *names* the same
 * strings §7 uses) - the account/tenant/accounting-period resolution and
 * actual DB writes live entirely in commit(), once, generically, rather
 * than being repeated in all 36 methods.
 */
final class EntryInput
{
    /** @var LineInput[] */
    public readonly array $lines;

    public function __construct(
        public readonly string $eventType,
        public readonly string $referenceType,
        public readonly ?string $externalReference,
        LineInput ...$lines,
    ) {
        $this->lines = $lines;
    }

    public function totalDebits(): Money
    {
        return Money::sum(...array_map(fn (LineInput $l) => $l->debit, $this->lines));
    }

    public function totalCredits(): Money
    {
        return Money::sum(...array_map(fn (LineInput $l) => $l->credit, $this->lines));
    }

    /**
     * Integer cents throughout means this is exact equality, not a
     * tolerance check - there is no float drift left to tolerate. A real
     * improvement over the reference file's epsilon-based isBalanced(),
     * which existed specifically to work around float imprecision this
     * class doesn't have.
     */
    public function isBalanced(): bool
    {
        return $this->totalDebits()->equals($this->totalCredits());
    }

    public function amountFor(string $account, string $side = 'debit'): Money
    {
        $matching = array_filter($this->lines, fn (LineInput $l) => $l->account === $account);

        return Money::sum(...array_map(
            fn (LineInput $l) => $side === 'debit' ? $l->debit : $l->credit,
            $matching,
        ));
    }
}
