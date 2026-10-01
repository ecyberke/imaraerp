<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class AssetComponent extends Model
{
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'name',
        'purchase_cost_cents',
        'residual_value_cents',
        'useful_life_years',
        'depreciation_method',
        'lifespan_change_reason',
        'schedule_reset_at',
    ];

    protected $hidden = ['purchase_cost_cents', 'residual_value_cents'];

    protected $appends = ['purchase_cost', 'residual_value'];

    protected function casts(): array
    {
        return [
            'schedule_reset_at' => 'date',
            'purchase_cost_cents' => MoneyCast::class,
            'residual_value_cents' => MoneyCast::class,
        ];
    }

    protected function purchaseCost(): Attribute
    {
        return Attribute::make(get: fn () => $this->purchase_cost_cents);
    }

    protected function residualValue(): Attribute
    {
        return Attribute::make(get: fn () => $this->residual_value_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function depreciationEntries()
    {
        return $this->hasMany(AssetDepreciationEntry::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
