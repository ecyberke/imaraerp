<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * §3.7: "boq_line_allocations (many-to-many, BOQLine <-> Milestone with
 * a percentage_of_value per line) - the single primitive." Bulk
 * section-level allocation expands to one row per line at allocation
 * time (MilestoneService::allocateSection()), not a separate mechanism.
 */
class MilestoneBoqLineAllocation extends Model
{
    protected $table = 'milestone_boq_line_allocations';

    protected $fillable = [
        'tenant_id',
        'milestone_id',
        'boq_line_id',
        'percentage_of_value',
    ];

    protected function casts(): array
    {
        return ['percentage_of_value' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function milestone()
    {
        return $this->belongsTo(Milestone::class);
    }

    public function boqLine()
    {
        return $this->belongsTo(BoqLine::class, 'boq_line_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
