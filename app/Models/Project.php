<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    public const STATUSES = ['initiated', 'in_progress', 'complete', 'defects_liability', 'closed', 'cancelled'];

    protected $fillable = [
        'tenant_id',
        'party_id',
        'analytic_account_id',
        'name',
        'specification_file_path',
        'status',
        'completed_at',
        'dlp_ready_to_close',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'date',
            'dlp_ready_to_close' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function analyticAccount()
    {
        return $this->belongsTo(AnalyticAccount::class);
    }

    /** §3.1: Boq is polymorphic (boqable Subcontract|Project) - a Project's own client-facing BOQ. */
    public function boq()
    {
        return $this->morphOne(Boq::class, 'boqable');
    }

    public function milestones()
    {
        return $this->hasMany(Milestone::class);
    }

    public function variationOrders()
    {
        return $this->hasMany(VariationOrder::class);
    }

    public function defects()
    {
        return $this->hasMany(Defect::class);
    }

    public function subcontracts()
    {
        return $this->hasMany(Subcontract::class);
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function assetAssignments()
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function equipmentHireContracts()
    {
        return $this->hasMany(EquipmentHireContract::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
