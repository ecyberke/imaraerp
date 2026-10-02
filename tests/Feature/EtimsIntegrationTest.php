<?php

namespace Tests\Feature;

use App\Models\EtimsItemClassification;
use App\Models\EtimsSubmission;
use App\Models\IntegrationCredential;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantFeatureFlag;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * etims-integration branch (Phase 3): a deliberately thin first slice -
 * device initialization (establishes cmcKey) and item classification
 * code sync only, see EtimsService's own docblock for why Sales/Item/
 * Purchase/Stock Management aren't built yet. Every Http::fake() response
 * shape here is this app's own best-effort guess at KRA's response
 * envelope (no official example was available), not a verified KRA
 * response - these tests prove EtimsService's own parsing/fallback logic
 * behaves correctly against that guess, not that the guess is correct.
 */
class EtimsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
        // TASK-100: integrations default OFF; these tests exercise the enabled path.
        app(FeatureFlagService::class)->set($this->tenant, TenantFeatureFlag::ETIMS, true, 'test', 'test');
    }

    private function headersFor(string $email, string $role): array
    {
        return ['Authorization' => 'Bearer '.$this->userFor($email, $role)->createToken('test')->plainTextToken];
    }

    private function userFor(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'),
            'mfa_enabled' => in_array($role, ['finance', 'admin'], true),
        ]);
    }

    private function configureEtimsCredentials(array $headers, array $overrides = []): void
    {
        $this->withHeaders($headers)->putJson('/api/integration-credentials/etims', [
            'is_active' => true,
            'fields' => array_merge([
                'environment' => 'sandbox',
                'tin' => 'P000000000A',
                'bhf_id' => '00',
                'dvc_srl_no' => 'TEST-SERIAL-1',
            ], $overrides),
        ])->assertOk();
    }

    public function test_device_cannot_be_initialized_without_tin_branch_and_serial_configured(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');

        $this->withHeaders($headers)->postJson('/api/etims/initialize-device')->assertStatus(500);
    }

    public function test_device_initialization_stores_the_communication_key_on_success(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureEtimsCredentials($headers);

        Http::fake([
            '*/selectInitOsdcInfo' => Http::response([
                'resultCd' => '000', 'resultMsg' => 'Success', 'cmcKey' => 'fake-communication-key',
            ]),
        ]);

        $submission = $this->withHeaders($headers)->postJson('/api/etims/initialize-device')->assertOk()->json();

        $this->assertSame('success', $submission['status']);
        $this->assertDatabaseHas('etims_submissions', ['tenant_id' => $this->tenant->id, 'type' => 'device_init', 'status' => 'success']);

        $stored = IntegrationCredential::where('tenant_id', $this->tenant->id)->where('provider', 'etims')->first();
        $this->assertSame('fake-communication-key', $stored->credentials['cmc_key']);
    }

    public function test_device_initialization_fails_cleanly_when_no_cmc_key_is_found_in_the_response(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureEtimsCredentials($headers);

        Http::fake([
            '*/selectInitOsdcInfo' => Http::response(['resultCd' => '999', 'resultMsg' => 'Unexpected envelope shape']),
        ]);

        $submission = $this->withHeaders($headers)->postJson('/api/etims/initialize-device')->assertOk()->json();

        $this->assertSame('failed', $submission['status']);
        $this->assertNull(
            IntegrationCredential::where('tenant_id', $this->tenant->id)->where('provider', 'etims')->first()->credentials['cmc_key'] ?? null
        );
    }

    public function test_item_classification_sync_requires_the_device_to_already_be_initialized(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureEtimsCredentials($headers);

        $this->withHeaders($headers)->postJson('/api/etims/sync-item-classifications')->assertStatus(500);
    }

    public function test_item_classification_sync_caches_the_returned_codes(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureEtimsCredentials($headers, ['cmc_key' => 'fake-communication-key']);

        Http::fake([
            '*/selectItemClsList' => Http::response([
                'resultCd' => '000',
                'data' => ['itemClsList' => [
                    ['itemClsCd' => '1010150600', 'itemClsNm' => 'Cement'],
                    ['itemClsCd' => '5059690800', 'itemClsNm' => 'Hardware'],
                ]],
            ]),
        ]);

        $this->withHeaders($headers)->postJson('/api/etims/sync-item-classifications')->assertOk();

        $this->assertSame(2, EtimsItemClassification::where('tenant_id', $this->tenant->id)->count());
        $this->assertDatabaseHas('etims_item_classifications', [
            'tenant_id' => $this->tenant->id, 'code' => '1010150600', 'name' => 'Cement',
        ]);

        $listed = $this->withHeaders($headers)->getJson('/api/etims/item-classifications')->assertOk()->json();
        $this->assertCount(2, $listed);
    }

    public function test_non_admin_cannot_initialize_the_device_or_sync_item_classifications(): void
    {
        $headers = $this->headersFor('proc@example.com', 'procurement');

        $this->withHeaders($headers)->postJson('/api/etims/initialize-device')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/etims/sync-item-classifications')->assertStatus(403);
    }

    public function test_raw_response_is_always_retained_even_on_failure(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureEtimsCredentials($headers);

        Http::fake([
            '*/selectInitOsdcInfo' => Http::response(['resultCd' => '901', 'resultMsg' => 'Invalid device'], 200),
        ]);

        $this->withHeaders($headers)->postJson('/api/etims/initialize-device')->assertOk();

        $submission = EtimsSubmission::where('tenant_id', $this->tenant->id)->where('type', 'device_init')->first();
        $this->assertSame('901', $submission->result_code);
        $this->assertSame(['resultCd' => '901', 'resultMsg' => 'Invalid device'], $submission->response_payload);
    }
}
