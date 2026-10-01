<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Party;
use App\Models\Resource;
use App\Models\ResourceAssignment;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ResourceAssignmentService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * labour-resourcing branch (execution_plan.md): Resource,
 * ResourceAssignment with scheduled/active/completed/cancelled,
 * date-driven automatic transitions with manual override.
 */
class LabourResourcingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Currency $kes;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
        $this->customer = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jengo Ltd', 'type' => 'customer',
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

    private function makeSalesOrder(): SalesOrder
    {
        return SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed', 'invoice_policy' => 'on_order', 'status' => 'approved',
            'currency_id' => $this->kes->id, 'exchange_rate' => 1,
        ]);
    }

    public function test_resource_and_assignment_can_be_created_against_a_sales_order(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $salesOrder = $this->makeSalesOrder();

        $resource = $this->withHeaders($headers)->postJson('/api/resources', [
            'type' => 'contractor', 'party_id' => $this->customer->id, 'skill_category' => 'Electrical',
        ])->assertCreated();

        $assignment = $this->withHeaders($headers)->postJson("/api/resources/{$resource->json('id')}/resource-assignments", [
            'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->addDays(5)->toDateString(),
            'block_end_date' => now()->addDays(10)->toDateString(),
        ])->assertCreated();

        // Starts in the future - stays 'scheduled', not yet auto-activated.
        $this->assertSame('scheduled', $assignment->json('status'));
    }

    public function test_assignment_cannot_reference_both_or_neither_project_and_sales_order(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $resource = $this->withHeaders($headers)->postJson('/api/resources', ['type' => 'internal'])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/resources/{$resource->json('id')}/resource-assignments", [
            'block_start_date' => now()->toDateString(), 'block_end_date' => now()->addDays(5)->toDateString(),
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson("/api/resources/{$resource->json('id')}/resource-assignments", [
            'project_id' => 1, 'sales_order_id' => $this->makeSalesOrder()->id,
            'block_start_date' => now()->toDateString(), 'block_end_date' => now()->addDays(5)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_assignment_starting_today_or_earlier_is_activated_immediately_on_creation(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $salesOrder = $this->makeSalesOrder();
        $resource = $this->withHeaders($headers)->postJson('/api/resources', ['type' => 'internal'])->assertCreated();

        $assignment = $this->withHeaders($headers)->postJson("/api/resources/{$resource->json('id')}/resource-assignments", [
            'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDays(2)->toDateString(),
            'block_end_date' => now()->addDays(5)->toDateString(),
        ])->assertCreated();

        $this->assertSame('active', $assignment->json('status'));
    }

    public function test_scheduled_job_advances_assignments_whose_dates_have_arrived(): void
    {
        $resource = Resource::create(['tenant_id' => $this->tenant->id, 'type' => 'internal']);
        $salesOrder = $this->makeSalesOrder();

        // Backdate an assignment directly (bypassing create()'s own
        // immediate-evaluation) to simulate one the daily job hasn't
        // reached yet, and one already overdue for completion.
        $toActivate = ResourceAssignment::create([
            'tenant_id' => $this->tenant->id, 'resource_id' => $resource->id, 'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDay(), 'block_end_date' => now()->addDays(10), 'status' => 'scheduled',
        ]);
        $toComplete = ResourceAssignment::create([
            'tenant_id' => $this->tenant->id, 'resource_id' => $resource->id, 'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDays(20), 'block_end_date' => now()->subDay(), 'status' => 'active',
        ]);
        $stillFuture = ResourceAssignment::create([
            'tenant_id' => $this->tenant->id, 'resource_id' => $resource->id, 'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->addDays(5), 'block_end_date' => now()->addDays(10), 'status' => 'scheduled',
        ]);

        $advanced = app(ResourceAssignmentService::class)->advanceScheduledTransitions();

        $this->assertSame(2, $advanced);
        $this->assertSame('active', $toActivate->fresh()->status);
        $this->assertSame('completed', $toComplete->fresh()->status);
        $this->assertSame('scheduled', $stillFuture->fresh()->status);
    }

    public function test_advance_status_console_command_runs_the_same_scan(): void
    {
        $resource = Resource::create(['tenant_id' => $this->tenant->id, 'type' => 'internal']);
        $salesOrder = $this->makeSalesOrder();
        $assignment = ResourceAssignment::create([
            'tenant_id' => $this->tenant->id, 'resource_id' => $resource->id, 'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDay(), 'block_end_date' => now()->addDays(10), 'status' => 'scheduled',
        ]);

        $this->artisan('resource-assignments:advance-status')->assertExitCode(0);

        $this->assertSame('active', $assignment->fresh()->status);
    }

    public function test_manual_end_early_overrides_the_planned_end_date(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $salesOrder = $this->makeSalesOrder();
        $resource = $this->withHeaders($headers)->postJson('/api/resources', ['type' => 'internal'])->assertCreated();
        $assignment = $this->withHeaders($headers)->postJson("/api/resources/{$resource->json('id')}/resource-assignments", [
            'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDays(2)->toDateString(),
            'block_end_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated();
        $this->assertSame('active', $assignment->json('status'));

        $ended = $this->withHeaders($headers)
            ->postJson("/api/resource-assignments/{$assignment->json('id')}/end-early")
            ->assertOk();
        $this->assertSame('completed', $ended->json('status'));
        $this->assertSame(BusinessTime::today()->toDateString(), substr($ended->json('block_end_date'), 0, 10));
    }

    public function test_manual_extend_reactivates_a_completed_assignment(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $resource = Resource::create(['tenant_id' => $this->tenant->id, 'type' => 'internal']);
        $salesOrder = $this->makeSalesOrder();
        $assignment = ResourceAssignment::create([
            'tenant_id' => $this->tenant->id, 'resource_id' => $resource->id, 'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDays(20), 'block_end_date' => now()->subDay(), 'status' => 'completed',
        ]);

        $extended = $this->withHeaders($headers)
            ->postJson("/api/resource-assignments/{$assignment->id}/extend", ['block_end_date' => now()->addDays(15)->toDateString()])
            ->assertOk();

        $this->assertSame('active', $extended->json('status'));
    }

    public function test_cancel_is_rejected_once_completed(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $resource = Resource::create(['tenant_id' => $this->tenant->id, 'type' => 'internal']);
        $salesOrder = $this->makeSalesOrder();
        $assignment = ResourceAssignment::create([
            'tenant_id' => $this->tenant->id, 'resource_id' => $resource->id, 'sales_order_id' => $salesOrder->id,
            'block_start_date' => now()->subDays(20), 'block_end_date' => now()->subDay(), 'status' => 'completed',
        ]);

        $this->withHeaders($headers)->postJson("/api/resource-assignments/{$assignment->id}/cancel")->assertStatus(500);
    }

    public function test_resource_assignment_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('pm@example.com', 'project_manager');
        $resource = $this->withHeaders($headers)->postJson('/api/resources', ['type' => 'internal'])->assertCreated();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'project_manager')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other PM', 'email' => 'other-pm@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->getJson("/api/resources/{$resource->json('id')}")->assertStatus(404);
    }
}
