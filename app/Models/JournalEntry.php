<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Architecture §3.9: written only through LedgerPostingService, never
 * edited directly by module code. Correction policy is reversal-only -
 * enforced by LedgerPostingService::reverse(), not by this model refusing
 * updates outright (financial-record immutability at the DB/policy level
 * is a defense-in-depth item for a later pass, not built here).
 */
class JournalEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'reference_type',
        'reference_id',
        'external_reference',
        'posting_date',
        'original_intended_posting_date',
        'accounting_period_id',
        'created_by',
        'reversal_of_journal_entry_id',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'original_intended_posting_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_journal_entry_id');
    }

    public function reversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_journal_entry_id');
    }

    public function totalDebits(): Money
    {
        return Money::sum(...$this->lines->map(fn (JournalLine $l) => $l->debit_cents)->all());
    }

    public function totalCredits(): Money
    {
        return Money::sum(...$this->lines->map(fn (JournalLine $l) => $l->credit_cents)->all());
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits()->equals($this->totalCredits());
    }

    public function amountFor(string $accountName, string $side = 'debit'): Money
    {
        $matching = $this->lines->filter(fn (JournalLine $l) => $l->account->name === $accountName);

        return Money::sum(...$matching->map(
            fn (JournalLine $l) => $side === 'debit' ? $l->debit_cents : $l->credit_cents,
        )->all());
    }
}
