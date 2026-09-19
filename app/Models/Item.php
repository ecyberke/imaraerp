<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'category_id',
        'type',
        'uom_id',
        'reorder_level',
        'standard_cost_cents',
        'is_active',
    ];

    // See Party::$hidden for why - Money's JSON form is a major-unit
    // string, which a "_cents"-named field would misrepresent by 100x.
    protected $hidden = [
        'standard_cost_cents',
    ];

    protected $appends = [
        'standard_cost',
    ];

    protected function casts(): array
    {
        return [
            'standard_cost_cents' => MoneyCast::class,
            'reorder_level' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected function standardCost(): Attribute
    {
        return Attribute::make(get: fn () => $this->standard_cost_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
