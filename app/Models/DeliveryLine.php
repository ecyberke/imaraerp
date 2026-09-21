<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class DeliveryLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'delivery_id',
        'sales_order_line_id',
        'quantity_delivered',
        'cogs_value_cents',
    ];

    protected $hidden = ['cogs_value_cents'];

    protected $appends = ['cogs_value'];

    protected function casts(): array
    {
        return [
            'quantity_delivered' => 'decimal:4',
            'cogs_value_cents' => MoneyCast::class,
        ];
    }

    protected function cogsValue(): Attribute
    {
        return Attribute::make(get: fn () => $this->cogs_value_cents);
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

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
