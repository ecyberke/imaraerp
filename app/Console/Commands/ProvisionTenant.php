<?php

namespace App\Console\Commands;

use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

/**
 * Ops/onboarding entry point for a brand-new client (architecture §3.1) -
 * the first admin invitation for a tenant has no existing authenticated
 * admin to send it, so it's issued from here rather than an HTTP endpoint.
 */
class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision {name : The tenant/company name} {admin-email : Email for the first admin invitation}';

    protected $description = 'Provision a new Tenant and invite its first admin user';

    public function handle(TenantProvisioningService $service): int
    {
        $invitation = $service->provision($this->argument('name'), $this->argument('admin-email'));

        $this->info("Tenant '{$this->argument('name')}' provisioned (id={$invitation->tenant_id}).");
        $this->info("Admin invitation issued to {$invitation->email}.");
        $this->line("Acceptance token: {$invitation->token}");
        $this->line('POST to /api/invitations/'.$invitation->token.'/accept with {name, password, password_confirmation} to activate.');

        return self::SUCCESS;
    }
}
