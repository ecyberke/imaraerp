<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class EmploymentContract extends Model
{
    public const PAY_FREQUENCIES = ['monthly', 'weekly', 'daily'];

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'contract_type',
        'pay_frequency',
        'basic_salary_cents',
        'hourly_rate_cents',
        'daily_rate_cents',
        'overtime_multiplier_weekday',
        'overtime_multiplier_restday',
        'start_date',
        'end_date',
    ];

    protected $hidden = ['basic_salary_cents', 'hourly_rate_cents', 'daily_rate_cents'];

    protected $appends = ['basic_salary', 'hourly_rate', 'daily_rate'];

    protected function casts(): array
    {
        return [
            'overtime_multiplier_weekday' => 'decimal:2',
            'overtime_multiplier_restday' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'basic_salary_cents' => MoneyCast::class,
            'hourly_rate_cents' => MoneyCast::class,
            'daily_rate_cents' => MoneyCast::class,
        ];
    }

    protected function basicSalary(): Attribute
    {
        return Attribute::make(get: fn () => $this->basic_salary_cents);
    }

    protected function hourlyRate(): Attribute
    {
        return Attribute::make(get: fn () => $this->hourly_rate_cents);
    }

    protected function dailyRate(): Attribute
    {
        return Attribute::make(get: fn () => $this->daily_rate_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
