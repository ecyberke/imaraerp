<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * §3.12. purchase_cost/residual_value/useful_life_years are the CURRENT
 * depreciable basis - AssetRevaluationService updates them going forward
 * on a revaluation, they are not frozen-at-acquisition historicals.
 * #[ObservedBy(AuditLogObserver::class)] satisfies §3.12's "a
 * lifespan_change_reason is logged via AuditLog when it changes" - the
 * observer's generic old/new-value diff on every update() already covers
 * this attribute once attached, no separate logging call needed.
 */
#[ObservedBy(AuditLogObserver::class)]
class Asset extends Model
{
    public const ASSET_TYPES = ['fixed_asset', 'plant_equipment'];

    public const DEPRECIATION_METHODS = ['straight_line', 'sum_of_digits', 'diminishing_balance'];

    public const STATUSES = ['in_use', 'under_maintenance', 'disposed', 'written_off'];

    protected $fillable = [
        'tenant_id',
        'asset_number',
        'name',
        'category_id',
        'asset_type',
        'serial_number',
        'location_id',
        'custodian_party_id',
        'date_of_purchase',
        'purchase_cost_cents',
        'residual_value_cents',
        'useful_life_years',
        'depreciation_method',
        'warranty_years',
        'status',
        'lifespan_change_reason',
        'schedule_reset_at',
    ];

    protected $hidden = ['purchase_cost_cents', 'residual_value_cents'];

    protected $appends = ['purchase_cost', 'residual_value'];

    protected function casts(): array
    {
        return [
            'date_of_purchase' => 'date',
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

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function custodian()
    {
        return $this->belongsTo(Party::class, 'custodian_party_id');
    }

    public function components()
    {
        return $this->hasMany(AssetComponent::class);
    }

    public function depreciationEntries()
    {
        return $this->hasMany(AssetDepreciationEntry::class);
    }

    public function revaluations()
    {
        return $this->hasMany(AssetRevaluation::class);
    }

    public function disposal()
    {
        return $this->hasOne(AssetDisposal::class);
    }

    public function assignments()
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * §3.12: "Total depreciation for the parent is the sum of its
     * components' depreciation" when componentised - the figure both
     * AssetRevaluationService and AssetDisposalService need "as of now,"
     * read from each component's (or the asset's own) latest
     * AssetDepreciationEntry rather than recomputed from a formula, since
     * an entry is the actual posted historical fact.
     */
    public function currentAccumulatedDepreciation(): Money
    {
        $this->loadMissing('components.depreciationEntries', 'depreciationEntries');

        if ($this->components->isNotEmpty()) {
            return Money::sum(...$this->components->map(
                fn (AssetComponent $component) => $component->depreciationEntries->sortBy('period')->last()?->accumulated_depreciation ?? Money::zero()
            )->all());
        }

        return $this->depreciationEntries->sortBy('period')->last()?->accumulated_depreciation ?? Money::zero();
    }
}
