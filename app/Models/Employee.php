<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * §3.11: no `on_leave` status - leave is a derived state from
 * LeaveRequest, never a parallel flag here. No resource_id column - see
 * the resources.employee_id FK-backfill migration's docblock for why
 * resource() reads the other direction instead.
 */
#[ObservedBy(AuditLogObserver::class)]
class Employee extends Model
{
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'casual', 'contract'];

    public const STATUSES = ['active', 'probation', 'notice_period', 'terminated'];

    protected $fillable = [
        'tenant_id',
        'employee_number',
        'name',
        'id_number',
        'kra_pin',
        'nssf_number',
        'shif_number',
        'helb_account_number',
        'employment_type',
        'date_of_hire',
        'date_of_exit',
        'cumulative_casual_days_worked',
        'casual_conversion_due',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_hire' => 'date',
            'date_of_exit' => 'date',
            'casual_conversion_due' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function resource()
    {
        return $this->hasOne(Resource::class);
    }

    public function contracts()
    {
        return $this->hasMany(EmploymentContract::class);
    }

    /** The contract active as of today - exactly one at any time per the non-overlap rule EmploymentContractService enforces. */
    public function activeContract()
    {
        return $this->hasOne(EmploymentContract::class)
            ->whereDate('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()))
            ->latest('start_date');
    }

    public function timesheets()
    {
        return $this->hasMany(Timesheet::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
