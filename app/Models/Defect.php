<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Defect extends Model
{
    public const SEVERITIES = ['minor', 'major', 'critical'];

    public const STATUSES = ['open', 'in_progress', 'rectified', 'verified', 'closed'];

    public const BLOCKING_STATUSES = ['open', 'in_progress'];

    protected $fillable = [
        'tenant_id',
        'project_id',
        'subcontract_id',
        'milestone_id',
        'description',
        'severity',
        'blocks_retention',
        'reported_by',
        'reported_date',
        'target_fix_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'blocks_retention' => 'boolean',
            'reported_date' => 'date',
            'target_fix_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function subcontract()
    {
        return $this->belongsTo(Subcontract::class);
    }

    public function milestone()
    {
        return $this->belongsTo(Milestone::class);
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
