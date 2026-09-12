<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\UserInvitation;
use Illuminate\Support\Str;

/**
 * Architecture §3.1's provisioning flow: a Tenant is created, an admin User
 * is invited via UserInvitation, they accept and set a password - only
 * then does onboarding (Chart of Accounts seed, DocumentSequence init)
 * proceed. This covers the first two steps.
 *
 * The very first admin for a brand-new tenant can't invite themselves (no
 * authenticated tenant user exists yet to perform the invite) - this is
 * the ops/console-triggered bootstrap entry point for that (see
 * tenant:provision artisan command), not a public self-service signup
 * endpoint. Every *subsequent* invitation is a normal authenticated
 * admin action against a real InvitationController (later work) - not
 * needed for platform-foundation's exit criterion, which only needs the
 * first admin invited and accepted end-to-end.
 */
class TenantProvisioningService
{
    public function provision(string $tenantName, string $adminEmail, ?string $planTier = null): UserInvitation
    {
        $tenant = Tenant::create([
            'name' => $tenantName,
            'status' => 'active',
            'plan_tier' => $planTier,
        ]);

        $adminRole = Role::where('tenant_id', $tenant->id)->where('name', 'admin')->first();

        return UserInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $adminEmail,
            'role_id' => $adminRole->id,
            'invited_by' => null, // system-issued, not another User
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
