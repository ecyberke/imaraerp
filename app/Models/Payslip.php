<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class Payslip extends Model
{
    public const STATUSES = ['draft', 'approved', 'paid'];

    protected $fillable = [
        'tenant_id',
        'payroll_run_id',
        'employee_id',
        'employment_contract_id',
        'period_start',
        'period_end',
        'gross_pay_cents',
        'taxable_pay_cents',
        'days_paid_not_worked',
        'paye_amount_cents',
        'personal_relief_applied_cents',
        'nssf_amount_cents',
        'shif_amount_cents',
        'housing_levy_amount_cents',
        'helb_amount_cents',
        'other_deductions_cents',
        'employer_nssf_amount_cents',
        'employer_housing_levy_amount_cents',
        'net_pay_cents',
        'status',
    ];

    protected $hidden = [
        'gross_pay_cents', 'taxable_pay_cents', 'paye_amount_cents', 'personal_relief_applied_cents',
        'nssf_amount_cents', 'shif_amount_cents', 'housing_levy_amount_cents', 'helb_amount_cents',
        'other_deductions_cents', 'employer_nssf_amount_cents', 'employer_housing_levy_amount_cents', 'net_pay_cents',
    ];

    protected $appends = [
        'gross_pay', 'taxable_pay', 'paye_amount', 'personal_relief_applied',
        'nssf_amount', 'shif_amount', 'housing_levy_amount', 'helb_amount',
        'other_deductions', 'employer_nssf_amount', 'employer_housing_levy_amount', 'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'days_paid_not_worked' => 'decimal:2',
            'gross_pay_cents' => MoneyCast::class,
            'taxable_pay_cents' => MoneyCast::class,
            'paye_amount_cents' => MoneyCast::class,
            'personal_relief_applied_cents' => MoneyCast::class,
            'nssf_amount_cents' => MoneyCast::class,
            'shif_amount_cents' => MoneyCast::class,
            'housing_levy_amount_cents' => MoneyCast::class,
            'helb_amount_cents' => MoneyCast::class,
            'other_deductions_cents' => MoneyCast::class,
            'employer_nssf_amount_cents' => MoneyCast::class,
            'employer_housing_levy_amount_cents' => MoneyCast::class,
            'net_pay_cents' => MoneyCast::class,
        ];
    }

    protected function grossPay(): Attribute
    {
        return Attribute::make(get: fn () => $this->gross_pay_cents);
    }

    protected function taxablePay(): Attribute
    {
        return Attribute::make(get: fn () => $this->taxable_pay_cents);
    }

    protected function payeAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->paye_amount_cents);
    }

    protected function personalReliefApplied(): Attribute
    {
        return Attribute::make(get: fn () => $this->personal_relief_applied_cents);
    }

    protected function nssfAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->nssf_amount_cents);
    }

    protected function shifAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->shif_amount_cents);
    }

    protected function housingLevyAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->housing_levy_amount_cents);
    }

    protected function helbAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->helb_amount_cents);
    }

    protected function otherDeductions(): Attribute
    {
        return Attribute::make(get: fn () => $this->other_deductions_cents);
    }

    protected function employerNssfAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->employer_nssf_amount_cents);
    }

    protected function employerHousingLevyAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->employer_housing_levy_amount_cents);
    }

    protected function netPay(): Attribute
    {
        return Attribute::make(get: fn () => $this->net_pay_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function employmentContract()
    {
        return $this->belongsTo(EmploymentContract::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
