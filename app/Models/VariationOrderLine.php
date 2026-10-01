<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class VariationOrderLine extends Model
{
    public const VARIATION_TYPES = ['quantity_change', 'rate_change', 'new_item', 'omission'];

    protected $fillable = [
        'tenant_id',
        'variation_order_id',
        'boq_line_id',
        'section_id',
        'variation_type',
        'quantity_delta',
        'rate_delta_cents',
        'amount_delta_cents',
        'description',
    ];

    protected $hidden = ['rate_delta_cents', 'amount_delta_cents'];

    protected $appends = ['rate_delta', 'amount_delta'];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:4',
            'rate_delta_cents' => MoneyCast::class,
            'amount_delta_cents' => MoneyCast::class,
        ];
    }

    protected function rateDelta(): Attribute
    {
        return Attribute::make(get: fn () => $this->rate_delta_cents);
    }

    protected function amountDelta(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_delta_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function variationOrder()
    {
        return $this->belongsTo(VariationOrder::class);
    }

    public function boqLine()
    {
        return $this->belongsTo(BoqLine::class, 'boq_line_id');
    }

    public function section()
    {
        return $this->belongsTo(BoqSection::class, 'section_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
