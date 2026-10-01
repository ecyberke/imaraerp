<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    public const STATUSES = ['draft', 'completed', 'cancelled'];

    protected $fillable = [
        'tenant_id',
        'bom_id',
        'sales_order_id',
        'warehouse_id',
        'quantity',
        'status',
        'rm_value_actual_cents',
        'fg_value_standard_cents',
        'wastage_variance_cents',
        'bom_variance_pct',
        'bom_variance_exceeded',
        'qc_status',
        'journal_entry_id',
        'completed_at',
    ];

    protected $hidden = ['rm_value_actual_cents', 'fg_value_standard_cents', 'wastage_variance_cents'];

    protected $appends = ['rm_value_actual', 'fg_value_standard', 'wastage_variance'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'bom_variance_pct' => 'decimal:4',
            'bom_variance_exceeded' => 'boolean',
            'completed_at' => 'datetime',
            'rm_value_actual_cents' => MoneyCast::class,
            'fg_value_standard_cents' => MoneyCast::class,
            'wastage_variance_cents' => MoneyCast::class,
        ];
    }

    protected function rmValueActual(): Attribute
    {
        return Attribute::make(get: fn () => $this->rm_value_actual_cents);
    }

    protected function fgValueStandard(): Attribute
    {
        return Attribute::make(get: fn () => $this->fg_value_standard_cents);
    }

    protected function wastageVariance(): Attribute
    {
        return Attribute::make(get: fn () => $this->wastage_variance_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function bom()
    {
        return $this->belongsTo(BillOfMaterial::class, 'bom_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function qualityChecks()
    {
        return $this->morphMany(QualityCheck::class, 'checkable');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
