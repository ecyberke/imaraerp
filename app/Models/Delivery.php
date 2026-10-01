<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    public const STATUSES = ['pending', 'dispatched', 'delivered'];

    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'warehouse_id',
        'status',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(DeliveryLine::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
