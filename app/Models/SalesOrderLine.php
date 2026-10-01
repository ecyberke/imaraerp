<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'item_id',
        'boq_line_id',
        'description',
        'quantity',
        'rate_cents',
        'amount_cents',
        'quantity_delivered',
    ];

    protected $hidden = ['rate_cents', 'amount_cents'];

    protected $appends = ['rate', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'rate_cents' => MoneyCast::class,
            'amount_cents' => MoneyCast::class,
        ];
    }

    protected function rate(): Attribute
    {
        return Attribute::make(get: fn () => $this->rate_cents);
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function boqLine()
    {
        return $this->belongsTo(BoqLine::class, 'boq_line_id');
    }

    public function deliveryLines()
    {
        return $this->hasMany(DeliveryLine::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
