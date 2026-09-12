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
        // 'sales' - not one of the two MFA-mandatory roles - so this test
        // stays focused on the create round trip; see the MFA-gate tests
        // below for the finance/admin-specific behavior.
        $tenant = Tenant::create(['name' => 'Tenant D', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'dana@example.com', 'sales');
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

    public function test_creating_a_dummy_record_writes_an_audit_log_entry(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant E', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'eve@example.com', 'sales');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', ['name' => 'Audited record']);

        $response->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'created',
            'entity_type' => DummyRecord::class,
            'entity_id' => $response->json('id'),
        ]);
    }

    public function test_intended_period_open_posts_directly_with_no_forwarding(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant F', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'frank@example.com', 'sales');
        $token = $user->createToken('test')->plainTextToken;

        // Seeded on tenant creation: the current calendar year is fully
        // open, so "today" lands directly in its own period.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', [
                'name' => 'On-time record',
                'record_date' => now()->toDateString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('original_intended_posting_date', null);
    }

    public function test_dated_into_a_closed_period_auto_forwards_instead_of_rejecting(): void
    {
        // Architecture §3.10: a closed-period posting is never rejected
        // outright - it auto-forwards to the earliest open period with the
        // original intent recorded. (execution_plan.md's own exit-criterion
        // wording says "rejected", which reads as a drift against §3.10's
        // explicit resolution - the architecture doc wins per the stated
        // priority order, flagged to the user rather than guessed at.)
        $tenant = Tenant::create(['name' => 'Tenant G', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'grace@example.com', 'sales');

        \App\Models\AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereMonth('start_date', now()->month)
            ->update(['status' => 'closed']);

        $token = $user->createToken('test')->plainTextToken;
        $intendedDate = now()->toDateString();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', [
                'name' => 'Backdated into a closed month',
                'record_date' => $intendedDate,
            ]);

        $response->assertCreated()
            ->assertJsonPath('original_intended_posting_date', $intendedDate);

        $this->assertNotNull($response->json('accounting_period_id'));
    }

    public function test_finance_and_admin_roles_are_blocked_from_sensitive_actions_without_mfa(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant H', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'henry@example.com', 'finance');
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', ['name' => 'Should be blocked'])
            ->assertForbidden()
            ->assertJsonPath('mfa_setup_required', true);
    }

    public function test_finance_user_can_act_after_completing_mfa_enrollment(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant I', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'ivy@example.com', 'finance');
        $token = $user->createToken('test')->plainTextToken;

        $setup = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/mfa/setup');
        $setup->assertOk();

        $secret = $setup->json('secret');
        $validCode = (new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/mfa/confirm', ['code' => $validCode])
            ->assertOk()
            ->assertJsonPath('mfa_enabled', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', ['name' => 'Now allowed'])
            ->assertCreated();
    }

    public function test_non_mfa_mandatory_roles_are_unaffected_by_the_mfa_gate(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant J', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'jack@example.com', 'site_supervisor');
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/dummy-records', ['name' => 'Fine without MFA'])
            ->assertCreated();
    }
}
