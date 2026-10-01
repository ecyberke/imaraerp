<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class AssetRevaluation extends Model
{
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'revaluation_date',
        'net_book_value_at_revaluation_cents',
        'accumulated_depreciation_at_revaluation_cents',
        'new_valuation_cents',
        'new_residual_value_cents',
        'new_useful_life_years',
        'reason',
        'approved_by',
        'journal_entry_id',
    ];

    protected $hidden = [
        'net_book_value_at_revaluation_cents', 'accumulated_depreciation_at_revaluation_cents',
        'new_valuation_cents', 'new_residual_value_cents',
    ];

    protected $appends = [
        'net_book_value_at_revaluation', 'accumulated_depreciation_at_revaluation',
        'new_valuation', 'new_residual_value',
    ];

    protected function casts(): array
    {
        return [
            'revaluation_date' => 'date',
            'net_book_value_at_revaluation_cents' => MoneyCast::class,
            'accumulated_depreciation_at_revaluation_cents' => MoneyCast::class,
            'new_valuation_cents' => MoneyCast::class,
            'new_residual_value_cents' => MoneyCast::class,
        ];
    }

    protected function netBookValueAtRevaluation(): Attribute
    {
        return Attribute::make(get: fn () => $this->net_book_value_at_revaluation_cents);
    }

    protected function accumulatedDepreciationAtRevaluation(): Attribute
    {
        return Attribute::make(get: fn () => $this->accumulated_depreciation_at_revaluation_cents);
    }

    protected function newValuation(): Attribute
    {
        return Attribute::make(get: fn () => $this->new_valuation_cents);
    }

    protected function newResidualValue(): Attribute
    {
        return Attribute::make(get: fn () => $this->new_residual_value_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
