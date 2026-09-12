<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_and_user_invitation_can_be_created(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Contractors',
            'status' => 'active',
            'plan_tier' => 'growth',
        ]);

        $adminRole = Role::where('name', 'admin')->first();

        $invitation = UserInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'admin@acme.test',
            'role_id' => $adminRole->id,
            'invited_by' => 1,
            'token' => 'invite-token-123',
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Acme Contractors']);
        $this->assertDatabaseHas('user_invitations', ['id' => $invitation->id, 'tenant_id' => $tenant->id]);
    }

    public function test_role_catalog_is_seeded(): void
    {
        $this->assertSame(9, Role::count());
        $this->assertNotNull(Role::where('name', 'admin')->first());
        $this->assertNotNull(Role::where('name', 'finance')->first());
        $this->assertNotNull(Role::where('name', 'site_supervisor')->first());
    }

    public function test_users_are_tenant_scoped(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = Role::where('name', 'admin')->first();
        $financeRole = Role::where('name', 'finance')->first();

        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'role_id' => $adminRole->id,
            'password' => bcrypt('password123'),
            'mfa_enabled' => false,
        ]);

        User::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'role_id' => $financeRole->id,
            'password' => bcrypt('password123'),
            'mfa_enabled' => true,
        ]);

        $this->assertSame([$userA->id], User::forTenant($tenantA)->pluck('id')->all());
        $this->assertCount(1, User::forTenant($tenantA)->get());
    }

    public function test_bearer_login_returns_access_token(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant C', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = Role::where('name', 'admin')->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'role_id' => $adminRole->id,
            'password' => bcrypt('password123'),
            'mfa_enabled' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'charlie@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.tenant_id', $tenant->id)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('token', fn ($token) => is_string($token) && $token !== '');
    }
}
