<?php

namespace Tests\Feature;

use App\Models\DummyRecord;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function roleFor(Tenant $tenant, string $name): Role
    {
        return Role::where('tenant_id', $tenant->id)->where('name', $name)->first();
    }

    private function makeUser(Tenant $tenant, string $email, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'role_id' => $this->roleFor($tenant, $role)->id,
            'password' => bcrypt('password123'),
            'mfa_enabled' => false,
        ]);
    }

    public function test_tenant_and_user_invitation_can_be_created(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Contractors',
            'status' => 'active',
            'plan_tier' => 'growth',
        ]);

        $invitation = UserInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'admin@acme.test',
            'role_id' => $this->roleFor($tenant, 'admin')->id,
            'invited_by' => 1,
            'token' => 'invite-token-123',
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Acme Contractors']);
        $this->assertDatabaseHas('user_invitations', ['id' => $invitation->id, 'tenant_id' => $tenant->id]);
    }

    public function test_each_tenant_is_seeded_with_its_own_role_catalog(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $this->assertSame(9, Role::where('tenant_id', $tenantA->id)->count());
        $this->assertSame(9, Role::where('tenant_id', $tenantB->id)->count());
        $this->assertSame(18, Role::count());

        // Same role name, two distinct tenant-owned rows, not one shared row.
        $this->assertNotSame(
            $this->roleFor($tenantA, 'admin')->id,
            $this->roleFor($tenantB, 'admin')->id,
        );
    }

    public function test_users_are_tenant_scoped_at_the_query_level(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $userA = $this->makeUser($tenantA, 'alice@example.com', 'admin');
        $this->makeUser($tenantB, 'bob@example.com', 'finance');

        $this->assertSame([$userA->id], User::forTenant($tenantA)->pluck('id')->all());
        $this->assertCount(1, User::forTenant($tenantA)->get());
    }

    public function test_bearer_login_returns_access_token(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant C', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'charlie@example.com', 'admin');

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

    /**
     * The exit criterion (execution_plan.md, clarified v20): an HTTP-level
     * test, not a query-level one. A query-level test only proves the
     * Eloquent global scope adds a WHERE clause — it can't catch a bug like
     * TenantScope resolving the wrong auth guard, which silently no-ops on
     * every real bearer-token request. This test authenticates as a real
     * User via a real bearer token and issues a real HTTP request for a
     * record belonging to a different tenant, through the full
     * routing -> middleware -> Policy -> query stack.
     */
    public function test_cross_tenant_record_access_returns_404_not_403(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $userA = $this->makeUser($tenantA, 'alice@example.com', 'admin');
        $userB = $this->makeUser($tenantB, 'bob@example.com', 'admin');

        $recordB = DummyRecord::create([
            'tenant_id' => $tenantB->id,
            'created_by' => $userB->id,
            'name' => "Tenant B's secret record",
        ]);

        $tokenA = $userA->createToken('test')->plainTextToken;

        // Tenant A's user must not be able to read Tenant B's record, and
        // the failure must be a 404 (record "doesn't exist" from A's point
        // of view) - a 403 would itself leak that the record exists.
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/dummy-records/{$recordB->id}")
            ->assertNotFound();
    }

    /**
     * Deliberately its own test rather than a second request in the test
     * above: Sanctum's guard memoizes the resolved user on first
     * resolution for the lifetime of the guard instance, and a single test
     * method's app container isn't rebooted between successive ->getJson()
     * calls - a second request with a different bearer token would just
     * see the first request's cached user. One authenticated request per
     * test avoids relying on that internal caching behavior.
     */
    public function test_user_can_read_their_own_tenants_record(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'bob@example.com', 'admin');

        $record = DummyRecord::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => "Tenant B's own record",
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/dummy-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('id', $record->id);
    }

    public function test_authenticated_user_can_create_a_tenant_scoped_dummy_record(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant D', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'dana@example.com', 'admin');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', ['name' => 'A record']);

        $response->assertCreated();

        $this->assertDatabaseHas('dummy_records', [
            'id' => $response->json('id'),
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
        ]);
    }
}
