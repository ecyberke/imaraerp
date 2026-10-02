<?php

namespace App\Services;

use App\Exceptions\IntegrationDisabledException;
use App\Models\Tenant;
use App\Models\TenantFeatureFlag;
use InvalidArgumentException;

/**
 * TASK-100 / ADR-006. Every flag defaults OFF: no row, or an unknown key,
 * reads as disabled. The tenant is always passed explicitly and pinned in
 * every query, so this works from console and queued code where no
 * authenticated user sets TenantScope.
 */
class FeatureFlagService
{
    public function isEnabled(Tenant $tenant, string $key): bool
    {
        return (bool) TenantFeatureFlag::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', $key)
            ->value('enabled');
    }

    /** @throws IntegrationDisabledException */
    public function ensureEnabled(Tenant $tenant, string $key): void
    {
        if (! $this->isEnabled($tenant, $key)) {
            throw new IntegrationDisabledException($tenant, $key);
        }
    }

    public function set(Tenant $tenant, string $key, bool $enabled, string $changedBy, string $reason): TenantFeatureFlag
    {
        if (! in_array($key, TenantFeatureFlag::KEYS, true)) {
            throw new InvalidArgumentException("Unknown feature flag '{$key}'. Known flags: ".implode(', ', TenantFeatureFlag::KEYS));
        }

        $flag = TenantFeatureFlag::firstOrNew(['tenant_id' => $tenant->id, 'key' => $key]);
        $flag->fill(['enabled' => $enabled, 'changed_by' => $changedBy, 'reason' => $reason])->save();

        return $flag;
    }

    /** @return array<string, bool> */
    public function all(Tenant $tenant): array
    {
        $stored = TenantFeatureFlag::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('enabled', 'key');

        return collect(TenantFeatureFlag::KEYS)
            ->mapWithKeys(fn (string $key) => [$key => (bool) ($stored[$key] ?? false)])
            ->all();
    }
}
