<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EtimsSubmission;
use App\Models\MpesaStkRequest;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantFeatureFlag;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-100 / ADR-006 / REQ-045: every external integration is gated by a
 * per-tenant flag that defaults OFF, and a disabled tenant never reaches
 * the provider - not even to create a local pending row.
 */
class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
    }

    private function headersFor(string $email, string $role, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $roleRow = Role::where('tenant_id', $tenant->id)->where('name', $role)->first();
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'),
            'mfa_enabled' => in_array($role, ['finance', 'admin'], true),
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function configureCredentials(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->withHeaders($headers)->putJson('/api/integration-credentials/mpesa', [
            'is_active' => true,
            'fields' => [
                'environment' => 'sandbox', 'shortcode' => '174379', 'consumer_key' => 'k',
                'consumer_secret' => 's', 'passkey' => 'p',
            ],
        ])->assertOk();
        $this->withHeaders($headers)->putJson('/api/integration-credentials/etims', [
            'is_active' => true,
            'fields' => ['environment' => 'sandbox', 'tin' => 'P000000000A', 'bhf_id' => '00', 'dvc_srl_no' => 'S-1', 'cmc_key' => 'c'],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_every_integration_flag_defaults_off(): void
    {
        $flags = app(FeatureFlagService::class);

        $this->assertSame([TenantFeatureFlag::ETIMS => false, TenantFeatureFlag::MPESA => false], $flags->all($this->tenant));
        $this->assertFalse($flags->isEnabled($this->tenant, TenantFeatureFlag::MPESA));
        $this->assertFalse($flags->isEnabled($this->tenant, TenantFeatureFlag::ETIMS));
        $this->assertFalse($flags->isEnabled($this->tenant, 'unknown.flag'));
    }

    public function test_stk_push_is_refused_without_calling_safaricom_when_mpesa_is_disabled(): void
    {
        $this->configureCredentials();
        Http::fake();
        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);

        $this->withHeaders($this->headersFor('finance@example.com', 'finance'))->postJson('/api/mpesa/stk-requests', [
            'party_id' => $party->id, 'amount' => 1000, 'phone_number' => '0712345678',
        ])->assertStatus(403)->assertJsonPath('flag', TenantFeatureFlag::MPESA);

        Http::assertNothingSent();
        $this->assertSame(0, MpesaStkRequest::count());
    }

    public function test_etims_calls_are_refused_without_calling_kra_when_etims_is_disabled(): void
    {
        $this->configureCredentials();
        Http::fake();
        $headers = $this->headersFor('admin2@example.com', 'admin');

        $this->withHeaders($headers)->postJson('/api/etims/initialize-device')
            ->assertStatus(403)->assertJsonPath('flag', TenantFeatureFlag::ETIMS);
        $this->withHeaders($headers)->postJson('/api/etims/sync-item-classifications')
            ->assertStatus(403)->assertJsonPath('flag', TenantFeatureFlag::ETIMS);

        Http::assertNothingSent();
        $this->assertSame(0, EtimsSubmission::count());
    }

    public function test_enabling_one_tenant_does_not_enable_another(): void
    {
        $other = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $flags = app(FeatureFlagService::class);

        $flags->set($this->tenant, TenantFeatureFlag::MPESA, true, 'ops', 'ACC-004 passed');

        $this->assertTrue($flags->isEnabled($this->tenant, TenantFeatureFlag::MPESA));
        $this->assertFalse($flags->isEnabled($other, TenantFeatureFlag::MPESA));
        $this->assertFalse($flags->isEnabled($this->tenant, TenantFeatureFlag::ETIMS));
    }

    public function test_operator_command_toggles_a_flag_and_the_change_is_audited(): void
    {
        $this->artisan('tenant:feature-flag', [
            'tenant' => $this->tenant->id, 'key' => TenantFeatureFlag::ETIMS, 'state' => 'on',
            '--by' => 'ops@imara', '--reason' => 'ACC-003 passed',
        ])->assertSuccessful();

        $flags = app(FeatureFlagService::class);
        $this->assertTrue($flags->isEnabled($this->tenant, TenantFeatureFlag::ETIMS));

        $this->artisan('tenant:feature-flag', [
            'tenant' => $this->tenant->id, 'key' => TenantFeatureFlag::ETIMS, 'state' => 'off',
            '--by' => 'ops@imara', '--reason' => 'KRA outage',
        ])->assertSuccessful();

        $this->assertFalse($flags->isEnabled($this->tenant, TenantFeatureFlag::ETIMS));
        $this->assertSame(2, AuditLog::where('entity_type', TenantFeatureFlag::class)->count());
    }

    public function test_operator_command_rejects_unknown_keys_and_missing_justification(): void
    {
        $this->artisan('tenant:feature-flag', [
            'tenant' => $this->tenant->id, 'key' => 'payroll.enabled', 'state' => 'on',
            '--by' => 'ops', '--reason' => 'x',
        ])->assertFailed();

        $this->artisan('tenant:feature-flag', [
            'tenant' => $this->tenant->id, 'key' => TenantFeatureFlag::MPESA, 'state' => 'on',
        ])->assertFailed();

        $this->assertSame(0, TenantFeatureFlag::count());
    }

    public function test_tenant_admin_has_no_http_route_to_enable_an_integration(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->map->uri();

        $this->assertEmpty($routes->filter(fn (string $uri) => str_contains($uri, 'feature-flag')));
    }
}
