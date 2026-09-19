<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'rate',
        'account_id',
        'type',
    ];

    protected function casts(): array
    {
        return [
            // Laravel's decimal cast yields a fixed-precision PHP string,
            // never a float - safe to hand straight to
            // Money::multiplyByRate(string $rate).
            'rate' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
