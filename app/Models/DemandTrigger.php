<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class DemandTrigger extends Model
{
    protected $fillable = [
        'tenant_id',
        'source_type',
        'source_id',
        'item_id',
        'warehouse_id',
        'quantity_needed',
        'status',
    ];

    protected function casts(): array
    {
        return ['quantity_needed' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
