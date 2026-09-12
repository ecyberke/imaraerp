<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-foundation's throwaway "dummy entity" (execution_plan.md exit
 * criterion) — proves tenant scoping + policy gating + audit logging +
 * AccountingPeriod gating + MFA gating end-to-end before any real ERP
 * entity exists to prove it against. See the migration's docblock.
 */
#[ObservedBy(AuditLogObserver::class)]
class DummyRecord extends Model
{
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'record_date',
        'accounting_period_id',
        'original_intended_posting_date',
    ];

    protected function casts(): array
    {
        return [
            'record_date' => 'date',
            'original_intended_posting_date' => 'date',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }
}
