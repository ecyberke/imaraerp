<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class GRNLine extends Model
{
    protected $table = 'grn_lines';

    protected $fillable = [
        'tenant_id',
        'grn_id',
        'purchase_order_line_id',
        'item_id',
        'quantity_received',
        'unit_cost_cents',
        'quarantine_status',
        'stock_quarantine_id',
    ];

    protected $hidden = ['unit_cost_cents'];

    protected $appends = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
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

    public function grn()
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'grn_id');
    }

    public function purchaseOrderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function stockQuarantine()
    {
        return $this->belongsTo(StockQuarantine::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
