<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'credit_limit_cents',
        'payment_terms',
        'tax_residency_status',
        'is_active',
    ];

    // Money's own JSON form is a major-unit decimal string ("500000.00")
    // - a field literally named credit_limit_cents showing that value
    // would read as 500,000 cents (KES 5,000) to any API consumer, which
    // is wrong by a factor of 100. Hidden from JSON in favor of the
    // cleanly-named credit_limit accessor below; still the real fillable/
    // cast column for every internal read/write.
    protected $hidden = [
        'credit_limit_cents',
    ];

    protected $appends = [
        'credit_limit',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit_cents' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    protected function creditLimit(): Attribute
    {
        return Attribute::make(get: fn () => $this->credit_limit_cents);
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
