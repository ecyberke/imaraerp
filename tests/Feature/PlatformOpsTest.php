<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\UserInvitation;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_endpoint_reports_database_connectivity(): void
    {
        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('db', true);
    }

    public function test_every_api_response_carries_a_correlation_id(): void
    {
        $this->getJson('/api/ready')
            ->assertHeader('X-Correlation-Id');
    }

    public function test_correlation_id_is_echoed_back_when_provided(): void
    {
        $this->withHeader('X-Correlation-Id', 'test-correlation-123')
            ->getJson('/api/ready')
            ->assertHeader('X-Correlation-Id', 'test-correlation-123');
    }

    /**
     * Account lockout after repeated failures (§1.1) - distinct from the
     * route-level throttle:5,1, which is IP-keyed; this is keyed by the
     * attempted email itself.
     */
    public function test_repeated_failed_logins_lock_the_account_out(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = \App\Models\Role::where('tenant_id', $tenant->id)->where('name', 'admin')->first();

        \App\Models\User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Karl',
            'email' => 'karl@example.com',
            'role_id' => $adminRole->id,
            'password' => bcrypt('correct-password'),
            'mfa_enabled' => false,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'karl@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // 6th attempt, even with the CORRECT password, is locked out.
        $this->postJson('/api/auth/login', [
            'email' => 'karl@example.com',
            'password' => 'correct-password',
        ])->assertStatus(422)->assertJsonFragment(['Too many failed attempts. Try again in 900 seconds.']);
    }

    public function test_prune_expired_invitations_command_removes_only_stale_unaccepted_ones(): void
    {
        $expired = (new TenantProvisioningService)->provision('Expired Co', 'owner@expired.test');
        UserInvitation::withoutGlobalScopes()->where('id', $expired->id)->update(['expires_at' => now()->subDay()]);

        $stillValid = (new TenantProvisioningService)->provision('Valid Co', 'owner@valid.test');

        $this->artisan('invitations:prune-expired')->assertExitCode(0);

        $this->assertDatabaseMissing('user_invitations', ['id' => $expired->id]);
        $this->assertDatabaseHas('user_invitations', ['id' => $stillValid->id]);
    }
}
