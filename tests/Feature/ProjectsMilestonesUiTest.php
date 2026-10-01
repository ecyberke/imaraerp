<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqLine;
use App\Models\BoqSection;
use App\Models\ContractRetentionTerms;
use App\Models\Party;
use App\Models\Project;
use App\Models\RetentionAccount;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\RetentionReleaseService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * projects-milestones-ui branch (execution_plan.md): Project's full state
 * machine, Milestone billing derived from BOQ-line allocations,
 * VariationOrder mutating the BOQ on approval, Defect feeding finance-
 * billing's retention-release gate.
 */
class ProjectsMilestonesUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Party $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->client = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);
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

    /** Project + Boq (boqable=Project) + one section with two lines, KES 100,000 each (200,000 total). */
    private function makeProjectWithBoq(): array
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->client->id, 'name' => 'Riverside Apartments', 'status' => 'initiated',
        ]);
        $boq = Boq::create([
            'tenant_id' => $this->tenant->id, 'boqable_type' => Project::class, 'boqable_id' => $project->id, 'status' => 'approved',
        ]);
        $section = BoqSection::create(['tenant_id' => $this->tenant->id, 'boq_id' => $boq->id, 'name' => 'Foundations', 'sequence' => 1]);
        $lineA = BoqLine::create([
            'tenant_id' => $this->tenant->id, 'boq_id' => $boq->id, 'section_id' => $section->id,
            'description' => 'Excavation', 'unit' => 'm3', 'quantity' => 100,
            'rate_cents' => Money::fromMajor('1000'), 'amount_cents' => Money::fromMajor('100000'), 'line_type' => 'measured',
        ]);
        $lineB = BoqLine::create([
            'tenant_id' => $this->tenant->id, 'boq_id' => $boq->id, 'section_id' => $section->id,
            'description' => 'Foundation concrete', 'unit' => 'm3', 'quantity' => 50,
            'rate_cents' => Money::fromMajor('2000'), 'amount_cents' => Money::fromMajor('100000'), 'line_type' => 'measured',
        ]);
        $boq->update(['revised_contract_value_cents' => Money::fromMajor('200000')]);

        SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->client->id, 'project_id' => $project->id,
            'boq_id' => $boq->id, 'supply_path' => 'project', 'feasibility_status' => 'passed',
            'invoice_policy' => 'on_milestone', 'status' => 'approved',
            'currency_id' => \App\Models\Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first()->id,
            'exchange_rate' => 1,
        ]);

        return compact('project', 'boq', 'section', 'lineA', 'lineB');
    }

    public function test_project_full_lifecycle_via_state_machine(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $project = $this->withHeaders($headers)->postJson('/api/projects', [
            'party_id' => $this->client->id, 'name' => 'Riverside Apartments',
        ])->assertCreated();
        $this->assertSame('initiated', $project->json('status'));

        $started = $this->withHeaders($headers)->postJson("/api/projects/{$project->json('id')}/start")->assertOk();
        $this->assertSame('in_progress', $started->json('status'));

        $completed = $this->withHeaders($headers)->postJson("/api/projects/{$project->json('id')}/mark-complete")->assertOk();
        $this->assertSame('defects_liability', $completed->json('status'));
        $this->assertNotNull($completed->json('completed_at'));

        // Not yet DLP-eligible - dlp_duration_months hasn't elapsed.
        $this->withHeaders($headers)->postJson("/api/projects/{$project->json('id')}/close", [
            'version' => $completed->json('version'),
        ])->assertStatus(500);
    }

    public function test_closing_requires_dlp_ready_and_matching_version(): void
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->client->id, 'name' => 'Riverside',
            'status' => 'defects_liability', 'completed_at' => BusinessTime::today()->subMonths(13), 'version' => 1,
        ]);

        app(ProjectService::class)->evaluateDlpEligibility();
        $this->assertTrue((bool) $project->fresh()->dlp_ready_to_close);

        $headers = $this->headersFor('pm@example.com', 'project_manager');

        // Stale version rejected.
        $this->withHeaders($headers)->postJson("/api/projects/{$project->id}/close", ['version' => 1])->assertStatus(500);

        $current = $project->fresh();
        $closed = $this->withHeaders($headers)->postJson("/api/projects/{$project->id}/close", ['version' => $current->version])->assertOk();
        $this->assertSame('closed', $closed->json('status'));
    }

    public function test_dlp_eligibility_job_blocked_by_open_blocking_defect(): void
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->client->id, 'name' => 'Riverside',
            'status' => 'defects_liability', 'completed_at' => BusinessTime::today()->subMonths(13), 'version' => 1,
        ]);
        \App\Models\Defect::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'description' => 'Leaking roof',
            'severity' => 'major', 'blocks_retention' => true, 'reported_date' => BusinessTime::today(), 'status' => 'open',
        ]);

        $this->artisan('projects:evaluate-dlp-eligibility')->assertExitCode(0);

        $this->assertFalse((bool) $project->fresh()->dlp_ready_to_close);
    }

    public function test_cancel_writes_off_unbilled_milestones(): void
    {
        ['project' => $project] = $this->makeProjectWithBoq();
        $project->update(['status' => 'in_progress']);
        $milestone = app(\App\Services\MilestoneService::class)->create($project, 1, 'Foundations complete');

        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $cancelled = $this->withHeaders($headers)->postJson("/api/projects/{$project->id}/cancel", [
            'reason' => 'Client withdrew financing',
        ])->assertOk();

        $this->assertSame('cancelled', $cancelled->json('status'));
        $this->assertSame('closed', $milestone->fresh()->status);
        $this->assertDatabaseHas('write_offs', ['milestone_id' => $milestone->id]);
    }

    public function test_milestone_allocation_billing_amount_and_sign_off_flow(): void
    {
        ['project' => $project, 'lineA' => $lineA] = $this->makeProjectWithBoq();
        $headers = $this->headersFor('pm@example.com', 'project_manager');

        $milestone = $this->withHeaders($headers)->postJson("/api/projects/{$project->id}/milestones", [
            'sequence' => 1, 'description' => 'Excavation complete',
        ])->assertCreated();

        $allocated = $this->withHeaders($headers)->postJson("/api/milestones/{$milestone->json('id')}/allocate-line", [
            'boq_line_id' => $lineA->id, 'percentage_of_value' => 50,
        ])->assertCreated();

        $milestoneAfter = $this->withHeaders($headers)->getJson("/api/milestones/{$milestone->json('id')}")->assertOk();
        // 50% of lineA's 100,000 = 50,000.
        $this->assertSame('50000.00', $milestoneAfter->json('billing_amount'));

        $siteHeaders = $this->headersFor('site@example.com', 'site_supervisor');
        $this->withHeaders($siteHeaders)->postJson("/api/milestones/{$milestone->json('id')}/mark-utilized")->assertOk();
        $signedOff = $this->withHeaders($siteHeaders)->postJson("/api/milestones/{$milestone->json('id')}/sign-off")->assertOk();
        $this->assertSame('signed_off', $signedOff->json('status'));

        $invoiced = $this->withHeaders($headers)->postJson("/api/milestones/{$milestone->json('id')}/mark-invoiced")->assertOk();
        $this->assertSame('invoiced', $invoiced->json('status'));
        $this->assertTrue($invoiced->json('billing_locked'));

        // Locked billing can no longer have its allocations changed.
        $this->withHeaders($headers)->postJson("/api/milestones/{$milestone->json('id')}/allocate-line", [
            'boq_line_id' => $lineA->id, 'percentage_of_value' => 60,
        ])->assertStatus(500);
    }

    public function test_variation_order_approval_mutates_boq_and_recalculates_unlocked_milestones(): void
    {
        ['project' => $project, 'boq' => $boq, 'lineA' => $lineA] = $this->makeProjectWithBoq();
        $headers = $this->headersFor('pm@example.com', 'project_manager');

        $milestone = app(\App\Services\MilestoneService::class)->create($project, 1, 'Excavation complete');
        app(\App\Services\MilestoneService::class)->allocateLine($milestone, $lineA->id, '100');
        $this->assertSame('100000.00', $milestone->fresh()->billing_amount->toMajor());

        $vo = $this->withHeaders($headers)->postJson("/api/projects/{$project->id}/variation-orders", [
            'description' => 'Additional excavation depth',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/lines", [
            'boq_line_id' => $lineA->id, 'variation_type' => 'quantity_change',
            'quantity_delta' => 10, 'amount_delta' => 10000, 'description' => 'Extra 10m3 excavation',
        ])->assertCreated();

        $submitted = $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/submit")->assertOk();
        $this->assertSame('10000.00', $submitted->json('amount_delta'));

        $approved = $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/approve", [])->assertOk();
        $this->assertSame('approved', $approved->json('status'));

        $this->assertSame('110000.00', $lineA->fresh()->amount->toMajor());
        $this->assertSame('210000.00', $boq->fresh()->revised_contract_value->toMajor());
        // Milestone is 100% of lineA, unlocked - recalculated to the new amount.
        $this->assertSame('110000.00', $milestone->fresh()->billing_amount->toMajor());

        $executed = $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/execute")->assertOk();
        $this->assertSame('executed', $executed->json('status'));

        // Reversing restores the pre-VO state.
        $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/reverse", [
            'reason' => 'Site conditions changed, excavation not needed',
        ])->assertCreated();

        $this->assertSame('100000.00', $lineA->fresh()->amount->toMajor());
        $this->assertSame('200000.00', $boq->fresh()->revised_contract_value->toMajor());
        $this->assertSame('100000.00', $milestone->fresh()->billing_amount->toMajor());
    }

    public function test_variation_order_new_item_creates_a_boq_line_on_approval(): void
    {
        ['project' => $project, 'boq' => $boq, 'section' => $section] = $this->makeProjectWithBoq();
        $headers = $this->headersFor('pm@example.com', 'project_manager');

        $vo = $this->withHeaders($headers)->postJson("/api/projects/{$project->id}/variation-orders", [
            'description' => 'New drainage works',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/lines", [
            'section_id' => $section->id, 'variation_type' => 'new_item',
            'quantity_delta' => 5, 'rate_delta' => 3000, 'amount_delta' => 15000, 'description' => 'French drain',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/submit")->assertOk();
        $this->withHeaders($headers)->postJson("/api/variation-orders/{$vo->json('id')}/approve", [])->assertOk();

        $this->assertDatabaseHas('boq_lines', ['boq_id' => $boq->id, 'description' => 'French drain']);
        $this->assertSame('215000.00', $boq->fresh()->revised_contract_value->toMajor());
    }

    public function test_defect_blocking_retention_gates_retention_release_ready_status(): void
    {
        ['project' => $project] = $this->makeProjectWithBoq();
        $salesOrder = SalesOrder::where('project_id', $project->id)->first();

        $terms = ContractRetentionTerms::create([
            'tenant_id' => $this->tenant->id, 'contract_type' => SalesOrder::class, 'contract_id' => $salesOrder->id,
            'direction' => 'receivable', 'retention_percentage' => '5', 'retention_cap_cents' => Money::fromMajor('10000'),
            'first_release_trigger' => 'practical_completion', 'second_release_trigger' => 'dlp_end', 'dlp_duration_months' => 12,
        ]);
        $account = RetentionAccount::create([
            'tenant_id' => $this->tenant->id, 'contract_type' => SalesOrder::class, 'contract_id' => $salesOrder->id,
            'party_id' => $this->client->id, 'direction' => 'receivable', 'amount_cents' => Money::fromMajor('10000'),
        ]);
        $release = app(RetentionReleaseService::class)->request($account, 'dlp_end', '10000');

        $defect = \App\Models\Defect::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'description' => 'Cracked tile',
            'severity' => 'minor', 'blocks_retention' => true, 'reported_date' => BusinessTime::today(), 'status' => 'open',
        ]);

        $blocked = app(RetentionReleaseService::class)->markReady($release);
        $this->assertSame('blocked_defects', $blocked->status);

        $defect->update(['status' => 'closed']);

        $ready = app(RetentionReleaseService::class)->markReady($release->fresh());
        $this->assertSame('ready', $ready->status);
    }

    public function test_project_completion_percentage_on_milestone_policy(): void
    {
        ['project' => $project, 'lineA' => $lineA] = $this->makeProjectWithBoq();
        $milestone = app(\App\Services\MilestoneService::class)->create($project, 1, 'Excavation complete');
        app(\App\Services\MilestoneService::class)->allocateLine($milestone, $lineA->id, '100');
        $milestone->update(['status' => 'signed_off']);

        $percentage = app(ProjectService::class)->completionPercentage($project);

        // 100,000 signed_off / 200,000 total contract value = 50%.
        $this->assertSame('0.5000', $percentage);
    }

    public function test_project_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $project = $this->withHeaders($headers)->postJson('/api/projects', [
            'party_id' => $this->client->id, 'name' => 'Riverside Apartments',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'project_manager')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other PM', 'email' => 'other-pm@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->getJson("/api/projects/{$project->json('id')}")->assertStatus(404);
    }
}
