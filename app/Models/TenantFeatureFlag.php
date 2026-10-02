<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-100 / ADR-006. Read through FeatureFlagService::isEnabled(), never
 * directly - a missing row must mean OFF, which only the service guarantees.
 */
#[ObservedBy(AuditLogObserver::class)]
class TenantFeatureFlag extends Model
{
    public const ETIMS = 'etims.enabled';

    public const MPESA = 'mpesa.enabled';

    public const KEYS = [self::ETIMS, self::MPESA];

    protected $fillable = [
        'tenant_id',
        'key',
        'enabled',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
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
