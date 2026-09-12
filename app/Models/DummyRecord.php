<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-foundation's throwaway "dummy entity" (execution_plan.md exit
 * criterion) — proves tenant scoping + policy gating end-to-end before any
 * real ERP entity exists to prove it against. See the migration's docblock.
 */
class DummyRecord extends Model
{
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
