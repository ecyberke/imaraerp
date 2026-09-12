<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;

/**
 * Result of AccountingPeriodResolver::resolve(). Mirrors the two fields
 * JournalEntry will carry (architecture §3.9): the date actually posted,
 * and - only when forwarded out of a closed/locked period -
 * originalIntendedPostingDate, the date the caller actually asked for.
 */
final class AccountingPeriodResolution
{
    public function __construct(
        public readonly AccountingPeriod $period,
        public readonly CarbonInterface $postingDate,
        public readonly ?CarbonInterface $originalIntendedPostingDate,
    ) {
    }

    public function wasForwarded(): bool
    {
        return $this->originalIntendedPostingDate !== null;
    }
}
