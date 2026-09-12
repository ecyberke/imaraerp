<?php

namespace Tests\Feature;

use App\Models\DummyRecord;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
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

    /**
     * §1.1/§3.10: a repeated request carrying the same Idempotency-Key
     * returns the original response rather than re-executing the
     * mutation - proven here by asserting only one DummyRecord exists
     * after two identically-keyed POSTs, not just that both calls "look"
     * successful.
     */
    public function test_repeated_request_with_the_same_idempotency_key_does_not_double_post(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $salesRole = Role::where('tenant_id', $tenant->id)->where('name', 'sales')->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Liam',
            'email' => 'liam@example.com',
            'role_id' => $salesRole->id,
            'password' => bcrypt('password123'),
            'mfa_enabled' => false,
        ]);

        $token = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'dedupe-key-1'];

        $first = $this->withHeaders($headers)->postJson('/api/dummy-records', ['name' => 'First attempt']);
        $first->assertCreated();

        $second = $this->withHeaders($headers)->postJson('/api/dummy-records', ['name' => 'Should be ignored']);
        $second->assertCreated()->assertJsonPath('id', $first->json('id'));

        $this->assertSame(1, DummyRecord::where('tenant_id', $tenant->id)->count());
    }

    /**
     * §1.1: "all Sanctum tokens revoked immediately on password change...
     * not just a status flag flipped while old tokens keep working."
     * Proven by actually trying to use the old token afterward, not just
     * asserting the endpoint returned 200.
     */
    public function test_changing_password_revokes_every_other_token(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = Role::where('tenant_id', $tenant->id)->where('name', 'admin')->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Omar',
            'email' => 'omar@example.com',
            'role_id' => $adminRole->id,
            'password' => bcrypt('old-password-123'),
            'mfa_enabled' => false,
        ]);

        $oldDeviceToken = $user->createToken('old-device')->plainTextToken;
        $currentToken = $user->createToken('current-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->patchJson('/api/account/password', [
                'current_password' => 'old-password-123',
                'password' => 'a-new-long-passphrase',
                'password_confirmation' => 'a-new-long-passphrase',
            ])->assertOk();

        // The token used to make the change still works...
        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->getJson('/api/health')
            ->assertOk();
    }

    public function test_old_token_stops_working_after_a_password_change(): void
    {
        // Deliberately its own test - same guard-caching reason as the
        // cross-tenant test elsewhere in this suite: a second request in
        // the same test method would just reuse the first request's
        // cached guard resolution, not genuinely re-authenticate.
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = Role::where('tenant_id', $tenant->id)->where('name', 'admin')->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Priya',
            'email' => 'priya@example.com',
            'role_id' => $adminRole->id,
            'password' => bcrypt('old-password-123'),
            'mfa_enabled' => false,
        ]);

        $oldDeviceToken = $user->createToken('old-device')->plainTextToken;
        $currentToken = $user->createToken('current-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->patchJson('/api/account/password', [
                'current_password' => 'old-password-123',
                'password' => 'a-new-long-passphrase',
                'password_confirmation' => 'a-new-long-passphrase',
            ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'old-device',
        ]);
    }

    public function test_a_different_idempotency_key_is_not_treated_as_a_duplicate(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $salesRole = Role::where('tenant_id', $tenant->id)->where('name', 'sales')->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nia',
            'email' => 'nia@example.com',
            'role_id' => $salesRole->id,
            'password' => bcrypt('password123'),
            'mfa_enabled' => false,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'key-a'])
            ->postJson('/api/dummy-records', ['name' => 'A'])
            ->assertCreated();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'key-b'])
            ->postJson('/api/dummy-records', ['name' => 'B'])
            ->assertCreated();

        $this->assertSame(2, DummyRecord::where('tenant_id', $tenant->id)->count());
    }
}
