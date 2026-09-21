<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'purchase_requisition_id',
        'item_id',
        'quantity_needed',
        'notes',
    ];

    protected function casts(): array
    {
        return ['quantity_needed' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function purchaseRequisition()
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
