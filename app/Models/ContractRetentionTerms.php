<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ContractRetentionTerms extends Model
{
    protected $table = 'contract_retention_terms';

    protected $fillable = [
        'tenant_id',
        'contract_type',
        'contract_id',
        'direction',
        'retention_percentage',
        'retention_cap_cents',
        'first_release_trigger',
        'second_release_trigger',
        'dlp_duration_months',
        'release_trigger_source',
    ];

    protected $hidden = ['retention_cap_cents'];

    protected $appends = ['retention_cap'];

    protected function casts(): array
    {
        return [
            'retention_percentage' => 'decimal:4',
            'retention_cap_cents' => MoneyCast::class,
        ];
    }

    protected function retentionCap(): Attribute
    {
        return Attribute::make(get: fn () => $this->retention_cap_cents);
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
