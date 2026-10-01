<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class FinalSettlement extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_id',
        'payslip_id',
        'last_working_day',
        'days_worked_this_period',
        'accrued_leave_days',
        'leave_encashment_amount_cents',
        'final_paye_amount_cents',
    ];

    protected $hidden = ['leave_encashment_amount_cents', 'final_paye_amount_cents'];

    protected $appends = ['leave_encashment_amount', 'final_paye_amount'];

    protected function casts(): array
    {
        return [
            'last_working_day' => 'date',
            'days_worked_this_period' => 'decimal:2',
            'accrued_leave_days' => 'decimal:2',
            'leave_encashment_amount_cents' => MoneyCast::class,
            'final_paye_amount_cents' => MoneyCast::class,
        ];
    }

    protected function leaveEncashmentAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->leave_encashment_amount_cents);
    }

    protected function finalPayeAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->final_paye_amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
