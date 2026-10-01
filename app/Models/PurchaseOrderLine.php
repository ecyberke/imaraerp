<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'purchase_requisition_line_id',
        'item_id',
        'quantity_ordered',
        'unit_cost_cents',
    ];

    protected $hidden = ['unit_cost_cents'];

    protected $appends = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4',
            'unit_cost_cents' => MoneyCast::class,
        ];
    }

    protected function unitCost(): Attribute
    {
        return Attribute::make(get: fn () => $this->unit_cost_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function grnLines()
    {
        return $this->hasMany(GRNLine::class, 'purchase_order_line_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
