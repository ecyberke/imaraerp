<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class P9A extends Model
{
    protected $table = 'p9as';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'year',
        'taxable_pay_cents',
        'total_paye_cents',
        'personal_relief_applied_cents',
    ];

    protected $hidden = ['taxable_pay_cents', 'total_paye_cents', 'personal_relief_applied_cents'];

    protected $appends = ['taxable_pay', 'total_paye', 'personal_relief_applied'];

    protected function casts(): array
    {
        return [
            'taxable_pay_cents' => MoneyCast::class,
            'total_paye_cents' => MoneyCast::class,
            'personal_relief_applied_cents' => MoneyCast::class,
        ];
    }

    protected function taxablePay(): Attribute
    {
        return Attribute::make(get: fn () => $this->taxable_pay_cents);
    }

    protected function totalPaye(): Attribute
    {
        return Attribute::make(get: fn () => $this->total_paye_cents);
    }

    protected function personalReliefApplied(): Attribute
    {
        return Attribute::make(get: fn () => $this->personal_relief_applied_cents);
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
