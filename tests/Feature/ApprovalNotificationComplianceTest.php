<?php

namespace Tests\Feature;

use App\Models\ApprovalLimit;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * approval-notification-compliance branch (execution_plan.md):
 * ApprovalLimit UI with same-user maker-checker, Notification (full type
 * enum, digest batching), ComplianceDocument (blocking Progress Claim
 * certification), BankAccount reconciliation UI, Site Supervisor
 * GRN-creation permission.
 */
class ApprovalNotificationComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Currency $kes;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
        $this->customer = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);
    }

    private function headersFor(string $email, string $role): array
    {
        return ['Authorization' => 'Bearer '.$this->userFor($email, $role)->createToken('test')->plainTextToken];
    }

    /** §1.1: MFA mandatory for Finance/Admin on mutating routes - enrolled here as a precondition, not something these tests re-prove. */
    private function userFor(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'),
            'mfa_enabled' => in_array($role, ['finance', 'admin'], true),
        ]);
    }

    public function test_approval_limits_are_seeded_at_provisioning_and_admin_can_configure_more(): void
    {
        $this->assertDatabaseHas('approval_limits', ['tenant_id' => $this->tenant->id, 'entity_type' => 'purchase_order']);

        $headers = $this->headersFor('admin@example.com', 'admin');
        $hrRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'hr_manager')->first();

        $created = $this->withHeaders($headers)->postJson('/api/approval-limits', [
            'role_id' => $hrRole->id, 'entity_type' => 'purchase_order', 'max_amount' => 50000,
        ])->assertCreated();

        $this->assertSame('50000.00', $created->json('max_amount'));

        // Non-admin cannot configure approval authority. headersFor() itself
        // runs tenant-scoped queries (User::create()/Role::where()), and
        // TenantScope resolves Auth::guard('sanctum')->user() to apply the
        // tenant filter - that resolution permanently caches onto the guard
        // (RequestGuard::$user is never reset by setRequest()), so
        // forgetGuards() must run AFTER headersFor(), not before it, or the
        // stale admin identity from the first request gets baked in before
        // this second request ever dispatches.
        $procHeaders = $this->headersFor('proc@example.com', 'procurement');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($procHeaders)->postJson('/api/approval-limits', [
            'role_id' => $hrRole->id, 'entity_type' => 'purchase_order', 'max_amount' => 1,
        ])->assertStatus(403);
    }

    public function test_purchase_order_approval_requires_second_approver_above_threshold_from_a_different_user(): void
    {
        $headers = $this->headersFor('proc@example.com', 'procurement');
        $supplier = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Steel Co', 'type' => 'supplier',
            'tax_residency_status' => 'resident_certified',
        ]);

        // 300,000 > procurement's seeded 200,000 second-approval threshold.
        $po = $this->withHeaders($headers)->postJson('/api/purchase-orders', [
            'party_id' => $supplier->id, 'currency_id' => $this->kes->id,
            'lines' => [['item_id' => $this->makeItem()->id, 'quantity_ordered' => 1, 'unit_cost' => 300000]],
        ])->assertCreated();
        $this->assertSame('requisitioned', $po->json('status'));

        $this->withHeaders($headers)->postJson("/api/purchase-orders/{$po->json('id')}/submit-for-approval")->assertOk();

        // No second approver supplied - refused.
        $this->withHeaders($headers)->postJson("/api/purchase-orders/{$po->json('id')}/approve", [])->assertStatus(500);

        // Same user named as their own second approver - maker-checker violation.
        $procUser = $this->userFor('proc-self@example.com', 'procurement');
        $selfHeaders = ['Authorization' => 'Bearer '.$procUser->createToken('test')->plainTextToken];
        $this->withHeaders($selfHeaders)->postJson("/api/purchase-orders/{$po->json('id')}/approve", [
            'second_approver_id' => $procUser->id,
        ])->assertStatus(500);

        // A genuinely different finance user - succeeds.
        $financeUser = $this->userFor('finance-second@example.com', 'finance');
        $approved = $this->withHeaders($headers)->postJson("/api/purchase-orders/{$po->json('id')}/approve", [
            'second_approver_id' => $financeUser->id,
        ])->assertOk();
        $this->assertSame('approved', $approved->json('status'));

        $ordered = $this->withHeaders($headers)->postJson("/api/purchase-orders/{$po->json('id')}/mark-ordered")->assertOk();
        $this->assertSame('ordered', $ordered->json('status'));
    }

    public function test_credit_approval_override_above_threshold_requires_second_approval(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $poorCustomer = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Tight Budget Ltd', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified', 'credit_limit_cents' => Money::fromMajor('1000'),
        ]);
        $salesOrder = \App\Models\SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $poorCustomer->id, 'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed', 'invoice_policy' => 'on_order', 'status' => 'approved',
            'currency_id' => $this->kes->id, 'exchange_rate' => 1,
        ]);

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'credit',
            // 600,000 gross is well above finance's seeded 500,000 second-approval threshold.
            'lines' => [['description' => 'Large order', 'quantity' => 1, 'unit_price' => 517241.38]],
        ])->assertCreated();
        $id = $invoice->json('id');

        $approval = $this->withHeaders($headers)->postJson('/api/credit-approvals', ['invoice_id' => $id])
            ->assertCreated()->assertJsonPath('status', 'rejected');

        $this->withHeaders($headers)->postJson("/api/credit-approvals/{$approval->json('id')}/override", ['notes' => 'test'])
            ->assertStatus(500);

        $admin = $this->userFor('admin-second@example.com', 'admin');
        $this->withHeaders($headers)->postJson("/api/credit-approvals/{$approval->json('id')}/override", [
            'notes' => 'CFO approved', 'second_approver_id' => $admin->id,
        ])->assertOk()->assertJsonPath('status', 'overridden');
    }

    public function test_compliance_document_blocks_progress_claim_certification_until_valid(): void
    {
        $headers = $this->headersFor('proc@example.com', 'procurement');
        $subcontractor = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Roofing Sub', 'type' => 'contractor',
            'tax_residency_status' => 'resident_certified',
        ]);

        $subcontract = $this->withHeaders($headers)->postJson('/api/subcontracts', [
            'party_id' => $subcontractor->id, 'required_document_types' => ['insurance'],
        ])->assertCreated();

        $claim = $this->withHeaders($headers)->postJson("/api/subcontracts/{$subcontract->json('id')}/progress-claims", [
            'period' => '2026-09', 'amount_claimed' => 100,
        ])->assertCreated();

        // No ComplianceDocument at all yet - blocked.
        $this->withHeaders($headers)->postJson("/api/progress-claims/{$claim->json('id')}/certify", [
            'amount_certified' => 100, 'vat_rate' => 0.16, 'retention_percentage' => 0.10,
        ])->assertStatus(500);

        // Expired document - still blocked.
        $this->withHeaders($headers)->postJson("/api/parties/{$subcontractor->id}/compliance-documents", [
            'document_type' => 'insurance', 'issue_date' => '2020-01-01', 'expiry_date' => '2020-12-31',
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/progress-claims/{$claim->json('id')}/certify", [
            'amount_certified' => 100, 'vat_rate' => 0.16, 'retention_percentage' => 0.10,
        ])->assertStatus(500);

        // A currently-valid document - certification proceeds.
        $this->withHeaders($headers)->postJson("/api/parties/{$subcontractor->id}/compliance-documents", [
            'document_type' => 'insurance', 'issue_date' => BusinessTime::today()->toDateString(),
            'expiry_date' => BusinessTime::today()->addYear()->toDateString(),
        ])->assertCreated();
        $certified = $this->withHeaders($headers)->postJson("/api/progress-claims/{$claim->json('id')}/certify", [
            'amount_certified' => 100, 'vat_rate' => 0.16, 'retention_percentage' => 0.10,
        ])->assertOk();
        $this->assertSame('certified', $certified->json('status'));
    }

    public function test_compliance_document_expiry_boundary_is_inclusive(): void
    {
        $subcontractor = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Boundary Sub', 'type' => 'contractor',
            'tax_residency_status' => 'resident_certified',
        ]);
        $service = app(\App\Services\ComplianceDocumentService::class);

        $document = $service->create($subcontractor, [
            'document_type' => 'safety_cert', 'issue_date' => BusinessTime::today()->subYear(),
            'expiry_date' => BusinessTime::today(),
        ]);

        // Inclusive boundary: still not 'expired' on its own expiry date
        // (it's within the 30-day expiring_soon window, but that status
        // doesn't block certification either - only 'expired' does, per
        // missingOrInvalidDocumentTypes()).
        $this->assertNotSame('expired', $document->status);

        $subcontract = \App\Models\Subcontract::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $subcontractor->id,
            'required_document_types' => ['safety_cert'], 'status' => 'active',
        ]);
        $this->assertSame([], $service->missingOrInvalidDocumentTypes($subcontract));
    }

    public function test_notifications_are_created_and_digest_dispatches(): void
    {
        $tenant = $this->tenant;
        $user = $this->userFor('hr@example.com', 'hr_manager');
        $notifications = app(NotificationService::class);

        $immediate = $notifications->notify($user, 'qc_failure', 'Something failed QC.');
        $this->assertNotNull($immediate->sent_at);

        $digest = $notifications->notify($user, 'reorder_alert', 'Low stock.');
        $this->assertNull($digest->sent_at);

        $this->artisan('notifications:dispatch-digests')->assertExitCode(0);
        $this->assertNotNull($digest->fresh()->sent_at);

        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $this->withHeaders($headers)->postJson("/api/notifications/{$immediate->id}/mark-read")
            ->assertOk()->assertJsonPath('read_at', fn ($v) => $v !== null);
    }

    public function test_bank_reconciliation_summary_and_mark_reconciled(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $cashAccount = ChartOfAccount::where('tenant_id', $this->tenant->id)->where('name', 'Cash/Bank')->first();

        $bankAccount = BankAccount::create([
            'tenant_id' => $this->tenant->id, 'bank_name' => 'Equity', 'account_number' => '001',
            'currency_id' => $this->kes->id, 'gl_account_id' => $cashAccount->id, 'status' => 'active',
        ]);

        $salesOrder = \App\Models\SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed', 'invoice_policy' => 'on_order', 'status' => 'approved',
            'currency_id' => $this->kes->id, 'exchange_rate' => 1,
        ]);
        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Cement', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        $payment = $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'invoice_allocations' => [['invoice_id' => $invoice->json('id'), 'amount' => $invoice->json('net_payable')]],
            'method' => 'bank_transfer', 'bank_account_id' => $bankAccount->id,
        ])->assertCreated();

        $summary = $this->withHeaders($headers)->getJson("/api/bank-accounts/{$bankAccount->id}/reconciliation-summary")->assertOk();
        $this->assertCount(1, $summary->json('unreconciled_payments'));

        $reconciled = $this->withHeaders($headers)->postJson("/api/payments/{$payment->json('id')}/reconcile")->assertOk();
        $this->assertTrue($reconciled->json('is_reconciled'));

        $summaryAfter = $this->withHeaders($headers)->getJson("/api/bank-accounts/{$bankAccount->id}/reconciliation-summary")->assertOk();
        $this->assertCount(0, $summaryAfter->json('unreconciled_payments'));
    }

    public function test_site_supervisor_can_create_and_view_a_grn(): void
    {
        $procHeaders = $this->headersFor('proc@example.com', 'procurement');
        $supplier = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Timber Co', 'type' => 'supplier',
            'tax_residency_status' => 'resident_certified',
        ]);
        $po = $this->withHeaders($procHeaders)->postJson('/api/purchase-orders', [
            'party_id' => $supplier->id, 'currency_id' => $this->kes->id,
            'lines' => [['item_id' => $this->makeItem()->id, 'quantity_ordered' => 10, 'unit_cost' => 50]],
        ])->assertCreated();

        $siteHeaders = $this->headersFor('site@example.com', 'site_supervisor');
        $grn = $this->withHeaders($siteHeaders)->postJson("/api/purchase-orders/{$po->json('id')}/goods-receipt-notes", [])
            ->assertCreated();

        $this->withHeaders($siteHeaders)->getJson("/api/goods-receipt-notes/{$grn->json('id')}")->assertOk();
    }

    public function test_approval_limit_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $hrRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'hr_manager')->first();
        $limit = ApprovalLimit::where('tenant_id', $this->tenant->id)->where('entity_type', 'purchase_order')->first();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'admin')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Admin', 'email' => 'other-admin@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->putJson("/api/approval-limits/{$limit->id}", [
            'role_id' => $hrRole->id, 'entity_type' => 'purchase_order', 'max_amount' => 1,
        ])->assertStatus(404);
    }

    private function makeItem(): \App\Models\Item
    {
        $category = \App\Models\Category::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'General'],
            ['valuation_method' => 'fifo'],
        );
        $uom = \App\Models\UnitOfMeasure::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'PC'],
            ['name' => 'Piece'],
        );

        return \App\Models\Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'ITEM-'.uniqid(), 'name' => 'Generic Item',
            'category_id' => $category->id, 'type' => 'raw_material', 'uom_id' => $uom->id,
        ]);
    }
}
