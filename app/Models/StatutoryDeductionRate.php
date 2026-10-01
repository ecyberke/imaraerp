<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class StatutoryDeductionRate extends Model
{
    public const DEDUCTION_TYPES = ['paye', 'nssf', 'shif', 'housing_levy', 'helb', 'nita'];

    protected $fillable = [
        'tenant_id',
        'deduction_type',
        'effective_from',
        'rate_bands',
        'employer_rate_bands',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rate_bands' => 'array',
            'employer_rate_bands' => 'array',
        ];
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
