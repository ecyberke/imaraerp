<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    protected $fillable = [
        'tenant_id',
        'delivery_id',
        'sales_order_line_id',
        'item_id',
        'warehouse_id',
        'quantity_returned',
        'reason',
        'restock_status',
        'credit_note_id',
    ];

    protected function casts(): array
    {
        return ['quantity_returned' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class);
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
