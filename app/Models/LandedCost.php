<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class LandedCost extends Model
{
    protected $fillable = [
        'tenant_id',
        'grn_id',
        'cost_type',
        'amount_cents',
        'currency_id',
        'exchange_rate',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'exchange_rate' => 'decimal:6',
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function grn()
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'grn_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
