<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillOfMaterialLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'bill_of_materials_id',
        'raw_material_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_materials_id');
    }

    public function rawMaterialItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'raw_material_item_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
