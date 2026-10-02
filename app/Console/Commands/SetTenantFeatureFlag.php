<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\FeatureFlagService;
use Illuminate\Console\Command;

/**
 * Operator-only switch for TASK-100 / ADR-006 flags. Deliberately not an
 * HTTP endpoint: a tenant admin must not be able to enable an integration
 * whose provider contract hasn't passed ACC-003 / ACC-004 yet.
 */
class SetTenantFeatureFlag extends Command
{
    protected $signature = 'tenant:feature-flag
        {tenant : Tenant id}
        {key : Flag key, e.g. mpesa.enabled or etims.enabled}
        {state : on|off}
        {--by= : Who is making the change (required)}
        {--reason= : Why, e.g. the ACC-* reference that cleared it (required)}';

    protected $description = 'Enable or disable an external integration for one tenant';

    public function handle(FeatureFlagService $flags): int
    {
        $state = $this->argument('state');
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error("State must be 'on' or 'off'.");

            return self::INVALID;
        }

        if (! $this->option('by') || ! $this->option('reason')) {
            $this->error('Both --by and --reason are required.');

            return self::INVALID;
        }

        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("Tenant #{$this->argument('tenant')} not found.");

            return self::FAILURE;
        }

        try {
            $flags->set($tenant, $this->argument('key'), $state === 'on', $this->option('by'), $this->option('reason'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->info("{$this->argument('key')} is now {$state} for tenant '{$tenant->name}' (id={$tenant->id}).");

        return self::SUCCESS;
    }
}
