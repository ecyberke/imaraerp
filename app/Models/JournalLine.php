<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'journal_entry_id',
        'account_id',
        'analytic_account_id',
        'tax_code_id',
        'debit_cents',
        'credit_cents',
    ];

    protected function casts(): array
    {
        return [
            'debit_cents' => MoneyCast::class,
            'credit_cents' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // Defense-in-depth mirror of LineInput's own validation (§3.9's
        // JournalLine shape) - LedgerPostingService::commit() never builds
        // an invalid line, but this guards against any future direct
        // ::create() call bypassing that path.
        static::saving(function (JournalLine $line) {
            $debit = $line->debit_cents instanceof Money ? $line->debit_cents : Money::fromCents((int) $line->debit_cents);
            $credit = $line->credit_cents instanceof Money ? $line->credit_cents : Money::fromCents((int) $line->credit_cents);

            if ($debit->isPositive() && $credit->isPositive()) {
                throw new \InvalidArgumentException('A JournalLine cannot carry both a debit and a credit.');
            }
            if ($debit->isNegative() || $credit->isNegative()) {
                throw new \InvalidArgumentException('Debit/credit amounts must be non-negative.');
            }
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function analyticAccount(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
