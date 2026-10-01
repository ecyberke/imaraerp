<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Timesheet extends Model
{
    public const STATUSES = ['submitted', 'approved', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'date',
        'hours_normal',
        'hours_overtime_weekday',
        'hours_overtime_restday',
        'project_id',
        'approved_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours_normal' => 'decimal:2',
            'hours_overtime_weekday' => 'decimal:2',
            'hours_overtime_restday' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
