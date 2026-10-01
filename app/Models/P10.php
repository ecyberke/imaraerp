<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class P10 extends Model
{
    protected $table = 'p10s';

    protected $fillable = [
        'tenant_id',
        'month',
        'year',
        'employee_count',
        'total_gross_pay_cents',
        'total_paye_cents',
    ];

    protected $hidden = ['total_gross_pay_cents', 'total_paye_cents'];

    protected $appends = ['total_gross_pay', 'total_paye'];

    protected function casts(): array
    {
        return [
            'total_gross_pay_cents' => MoneyCast::class,
            'total_paye_cents' => MoneyCast::class,
        ];
    }

    protected function totalGrossPay(): Attribute
    {
        return Attribute::make(get: fn () => $this->total_gross_pay_cents);
    }

    protected function totalPaye(): Attribute
    {
        return Attribute::make(get: fn () => $this->total_paye_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
