<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class VariationOrderReversal extends Model
{
    protected $fillable = [
        'tenant_id',
        'variation_order_id',
        'reason',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function variationOrder()
    {
        return $this->belongsTo(VariationOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(VariationOrderReversalLine::class);
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
