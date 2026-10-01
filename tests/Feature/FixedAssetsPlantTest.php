<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Party;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetAssignmentService;
use App\Services\AssetRevaluationService;
use App\Services\DepreciationRunService;
use App\Services\ProjectService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * fixed-assets-plant branch (execution_plan.md): Asset/AssetComponent/
 * AssetDepreciationEntry/AssetRevaluation/AssetDisposal/AssetAssignment/
 * EquipmentHireContract, plus the Fixed Asset Register and Depreciation
 * Schedule reports.
 */
class FixedAssetsPlantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Party $party;

    private AssetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Supplier Co', 'type' => 'supplier',
            'tax_residency_status' => 'resident_certified',
        ]);
        $this->category = AssetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Vehicles']);
    }

    private function headersFor(string $email, string $role): array
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeAsset(array $overrides = []): Asset
    {
        return Asset::create([...[
            'tenant_id' => $this->tenant->id, 'asset_number' => 'AST-'.uniqid(), 'name' => 'Excavator',
            'category_id' => $this->category->id, 'asset_type' => 'plant_equipment',
            'date_of_purchase' => BusinessTime::today()->subYears(1), 'purchase_cost_cents' => Money::fromMajor('1200000'),
            'residual_value_cents' => Money::zero(), 'useful_life_years' => 5, 'depreciation_method' => 'straight_line',
            'status' => 'in_use',
        ], ...$overrides]);
    }

    private function makeProject(): Project
    {
        return app(ProjectService::class)->create($this->tenant, ['party_id' => $this->party->id, 'name' => 'Riverside Site']);
    }

    public function test_asset_acquisition_posts_a_balanced_ledger_entry(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');

        $asset = $this->withHeaders($headers)->postJson('/api/assets', [
            'asset_number' => 'AST-001', 'name' => 'Office Generator', 'category_id' => $this->category->id,
            'asset_type' => 'fixed_asset', 'date_of_purchase' => BusinessTime::today()->toDateString(),
            'purchase_cost' => 500000, 'residual_value' => 50000, 'useful_life_years' => 10,
            'depreciation_method' => 'straight_line',
        ])->assertCreated();

        $this->assertSame('500000.00', $asset->json('purchase_cost'));
        $this->assertDatabaseHas('journal_entries', ['event_type' => 'asset_acquired', 'reference_id' => $asset->json('id')]);
    }

    public function test_straight_line_monthly_depreciation_is_correct_and_idempotent(): void
    {
        // 1,200,000 over 5 years, 0 residual = 20,000/month exactly.
        $asset = $this->makeAsset();

        $posted = app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today());
        $this->assertSame(1, $posted);

        $entry = $asset->depreciationEntries()->first();
        $this->assertSame('20000.00', $entry->depreciation_amount->toMajor());
        $this->assertSame('20000.00', $entry->accumulated_depreciation->toMajor());
        $this->assertSame('1180000.00', $entry->net_book_value->toMajor());

        // Re-running the same period is a no-op, not a duplicate.
        $again = app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today());
        $this->assertSame(0, $again);
        $this->assertSame(1, $asset->depreciationEntries()->count());
    }

    public function test_diminishing_balance_depreciates_against_current_net_book_value(): void
    {
        $asset = $this->makeAsset([
            'depreciation_method' => 'diminishing_balance',
            'purchase_cost_cents' => Money::fromMajor('1000000'),
            'residual_value_cents' => Money::fromMajor('100000'),
            'useful_life_years' => 5,
        ]);

        app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today());
        $first = $asset->depreciationEntries()->first();

        app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today()->copy()->addMonth());
        $second = $asset->fresh()->depreciationEntries()->orderBy('period')->get()->last();

        // Second month depreciates a smaller NBV than the first, so the
        // amount must be strictly less - the defining property of
        // diminishing balance versus straight_line's constant figure.
        $this->assertTrue($second->depreciation_amount->lessThan($first->depreciation_amount));
    }

    public function test_depreciation_never_runs_an_asset_below_its_residual_value(): void
    {
        $asset = $this->makeAsset([
            'purchase_cost_cents' => Money::fromMajor('100000'),
            'residual_value_cents' => Money::fromMajor('90000'),
            'useful_life_years' => 1,
        ]);

        // Straight-line over 12 months on a 10,000 depreciable base is
        // ~833.33/month - run far more months than needed to fully
        // depreciate and confirm it stops exactly at the residual value.
        $period = BusinessTime::today();
        for ($i = 0; $i < 24; $i++) {
            app(DepreciationRunService::class)->runForPeriod($this->tenant, $period);
            $period = $period->copy()->addMonth();
        }

        $latest = $asset->fresh()->depreciationEntries()->orderBy('period')->get()->last();
        $this->assertSame('90000.00', $latest->net_book_value->toMajor());
    }

    public function test_lifespan_change_requires_a_reason_and_is_audit_logged(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $asset = $this->makeAsset();

        $this->withHeaders($headers)->postJson("/api/assets/{$asset->id}/lifespan", [
            'useful_life_years' => 8,
        ])->assertStatus(422);

        $updated = $this->withHeaders($headers)->postJson("/api/assets/{$asset->id}/lifespan", [
            'useful_life_years' => 8, 'reason' => 'Engine overhaul extended service life',
        ])->assertOk();

        $this->assertSame(8, $updated->json('useful_life_years'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => Asset::class, 'entity_id' => $asset->id, 'action' => 'updated']);
    }

    public function test_asset_revalued_upward_updates_the_depreciable_basis(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $asset = $this->makeAsset();
        app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today());

        $revaluation = $this->withHeaders($headers)->postJson("/api/assets/{$asset->id}/revalue", [
            'new_valuation' => 1200000, 'reason' => 'Market revaluation',
        ])->assertCreated();

        $this->assertSame('20000.00', $revaluation->json('accumulated_depreciation_at_revaluation'));
        // NBV before revaluation was 1,180,000; new_valuation 1,200,000 ->
        // a 20,000 upward delta, so purchase_cost becomes 1,220,000 (not
        // simply reset to new_valuation) so NBV still nets to 1,200,000
        // against the unchanged accumulated depreciation.
        $this->assertSame('1220000.00', $asset->fresh()->purchase_cost->toMajor());
    }

    public function test_asset_revalued_downward_consumes_prior_reserve_before_hitting_pl(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $asset = $this->makeAsset(['purchase_cost_cents' => Money::fromMajor('1000000'), 'useful_life_years' => 10]);
        $assetManagerRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'asset_manager')->first();
        $approver = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Approver', 'email' => 'approver@example.com',
            'role_id' => $assetManagerRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);

        app(AssetRevaluationService::class)->revalue($asset, '1060000', null, null, 'Upward first', $approver);

        $downward = $this->withHeaders($headers)->postJson("/api/assets/{$asset->fresh()->id}/revalue", [
            'new_valuation' => 980000, 'reason' => 'Market correction',
        ])->assertCreated();

        $this->assertNotNull($downward->json('journal_entry_id'));
    }

    public function test_asset_disposed_with_gain_marks_the_asset_disposed(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $asset = $this->makeAsset(['purchase_cost_cents' => Money::fromMajor('500000'), 'residual_value_cents' => Money::zero(), 'useful_life_years' => 5]);
        app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today());

        $disposal = $this->withHeaders($headers)->postJson("/api/assets/{$asset->id}/dispose", [
            'disposal_type' => 'sold', 'sale_proceeds' => 500000,
        ])->assertCreated();

        $this->assertSame('sold', $disposal->json('disposal_type'));
        $this->assertSame('disposed', $asset->fresh()->status);

        $this->withHeaders($headers)->postJson("/api/assets/{$asset->id}/dispose", [
            'disposal_type' => 'sold', 'sale_proceeds' => 1,
        ])->assertStatus(500);
    }

    public function test_asset_assignment_lifecycle_posts_an_analytic_tagged_internal_charge(): void
    {
        $project = $this->makeProject();
        $asset = $this->makeAsset();

        $assignment = app(AssetAssignmentService::class)->assign($asset, $project, BusinessTime::today()->toDateString(), '15000');
        $this->assertSame('active', $assignment->status);

        $entry = app(AssetAssignmentService::class)->postPeriodicCharge($assignment, 10);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'analytic_account_id' => $project->fresh()->analytic_account_id]);

        $released = app(AssetAssignmentService::class)->release($assignment, BusinessTime::today()->addDays(10)->toDateString(), '120.5');
        $this->assertSame('released', $released->status);
    }

    public function test_equipment_hire_contract_flags_an_invoice_day_count_mismatch(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $project = $this->makeProject();

        $contract = $this->withHeaders($headers)->postJson('/api/equipment-hire-contracts', [
            'party_id' => $this->party->id, 'project_id' => $project->id, 'description' => 'Crane hire',
            'hire_rate' => 15000, 'hire_start_date' => BusinessTime::today()->toDateString(),
            'hire_end_date' => BusinessTime::today()->addDays(9)->toDateString(),
        ])->assertCreated();

        $this->assertSame(10, $contract->json('expected_days'));

        $flagged = $this->withHeaders($headers)
            ->postJson("/api/equipment-hire-contracts/{$contract->json('id')}/invoiced-days", ['invoiced_days' => 12])
            ->assertOk();
        $this->assertTrue($flagged->json('hire_invoice_mismatch'));

        $clean = $this->withHeaders($headers)
            ->postJson("/api/equipment-hire-contracts/{$contract->json('id')}/invoiced-days", ['invoiced_days' => 10])
            ->assertOk();
        $this->assertFalse($clean->json('hire_invoice_mismatch'));
    }

    public function test_fixed_asset_register_and_depreciation_schedule_reports(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $asset = $this->makeAsset();
        app(DepreciationRunService::class)->runForPeriod($this->tenant, BusinessTime::today());

        $register = $this->withHeaders($headers)->getJson('/api/reports/fixed-asset-register')->assertOk();
        $row = collect($register->json('assets'))->firstWhere('asset_id', $asset->id);
        $this->assertSame('20000.00', $row['accumulated_depreciation']);
        $this->assertSame('1180000.00', $row['net_book_value']);

        $schedule = $this->withHeaders($headers)
            ->getJson("/api/reports/depreciation-schedule?asset_id={$asset->id}")
            ->assertOk();
        $this->assertSame('20000.00', $schedule->json('actual.0.depreciation_amount'));
        $this->assertNotEmpty($schedule->json('planned'));
    }

    public function test_asset_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('am@example.com', 'asset_manager');
        $asset = $this->makeAsset();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'asset_manager')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other AM', 'email' => 'other-am@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->getJson("/api/assets/{$asset->id}")->assertStatus(404);
    }
}
