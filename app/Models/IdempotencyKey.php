<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'key',
        'endpoint',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
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
