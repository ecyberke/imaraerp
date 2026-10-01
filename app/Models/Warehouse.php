<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'is_default',
        'is_in_transit',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_in_transit' => 'boolean',
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
