<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillOfMaterial extends Model
{
    protected $table = 'bill_of_materials';

    protected $fillable = [
        'tenant_id',
        'finished_good_item_id',
        'wastage_allowance_pct',
        'tolerance_pct',
        'source',
        'project_id',
    ];

    protected function casts(): array
    {
        return [
            'wastage_allowance_pct' => 'decimal:4',
            'tolerance_pct' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * §3.1: tolerance_pct "defaults to wastage_allowance_pct if not
     * separately set" - a read-time fallback, not copied into the column,
     * so a later change to wastage_allowance_pct is still reflected for
     * any BOM that never overrode tolerance_pct.
     */
    public function effectiveTolerancePct(): string
    {
        return $this->tolerance_pct ?? $this->wastage_allowance_pct;
    }

    public function finishedGoodItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'finished_good_item_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillOfMaterialLine::class, 'bill_of_materials_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
