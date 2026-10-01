<?php

namespace Tests\Feature;

use App\Models\ContractRetentionTerms;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProgressClaimService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * finance-billing exit criterion (execution_plan.md): "the full Invoice
 * -> Payment -> PaymentAllocation cycle posts correctly, including a
 * proportional Credit Note reversal, a Sales Return, a
 * client-withheld-WHT receipt, and a full OpeningBalanceBatch migration
 * for a simulated existing client - all matching ledger-core's verified
 * postings." (The credit-limit concurrency half of this criterion is
 * covered separately in CreditApprovalConcurrencyTest.php, for the same
 * RefreshDatabase-transaction-wrapping reason InventoryConcurrencyTest
 * is its own class.)
 */
class FinanceBillingTest extends TestCase
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
            'tax_residency_status' => 'resident_certified', 'credit_limit_cents' => Money::fromMajor('1000000'),
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'),
            'mfa_enabled' => in_array($role, ['finance', 'admin'], true),
        ]);
    }

    private function headersFor(string $email, string $role): array
    {
        $user = $this->makeUser($email, $role);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeSalesOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->customer->id,
            'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed',
            'invoice_policy' => 'on_order',
            'status' => 'approved',
            'currency_id' => $this->kes->id,
            'exchange_rate' => 1,
        ], $overrides));
    }

    // =========================================================================
    // Exit criterion: Invoice -> Payment -> PaymentAllocation cycle
    // =========================================================================

    public function test_cash_invoice_raises_without_credit_approval_and_payment_fully_settles_it(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id,
            'payment_terms' => 'cash',
            'lines' => [['description' => 'Cement delivery', 'quantity' => 10, 'unit_price' => 750]],
        ])->assertCreated();
        $this->assertSame('draft', $invoice->json('status'));
        $this->assertSame('not_required', $invoice->json('credit_approval_status'));
        $this->assertSame('7500.00', $invoice->json('gross_amount'));
        $this->assertSame('1200.00', $invoice->json('vat_amount')); // 16%
        $this->assertSame('8700.00', $invoice->json('net_payable'));

        $id = $invoice->json('id');
        $raised = $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk();
        $this->assertSame('raised', $raised->json('status'));
        $this->assertNotNull($raised->json('journal_entry_id'));

        $payment = $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'invoice_allocations' => [['invoice_id' => $id, 'amount' => 8700]],
            'method' => 'bank_transfer',
        ])->assertCreated();
        $this->assertSame('8700.00', $payment->json('amount'));

        $this->withHeaders($headers)->getJson("/api/invoices/{$id}")->assertJsonPath('status', 'paid');
    }

    public function test_credit_invoice_cannot_be_raised_until_credit_approved(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id,
            'payment_terms' => 'credit',
            'lines' => [['description' => 'Rebar', 'quantity' => 5, 'unit_price' => 12000]],
        ])->assertCreated();
        $id = $invoice->json('id');
        $this->assertSame('pending', $invoice->json('credit_approval_status'));

        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertStatus(500);

        $this->withHeaders($headers)->postJson('/api/credit-approvals', ['invoice_id' => $id])
            ->assertCreated()->assertJsonPath('status', 'approved');

        $this->withHeaders($headers)->getJson("/api/invoices/{$id}")->assertJsonPath('credit_approval_status', 'approved');

        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk()->assertJsonPath('status', 'raised');
    }

    public function test_credit_approval_blocks_over_limit_by_default_and_can_be_overridden(): void
    {
        $poorCustomer = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Tight Budget Ltd', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified', 'credit_limit_cents' => Money::fromMajor('1000'),
        ]);
        $salesOrder = $this->makeSalesOrder(['party_id' => $poorCustomer->id]);
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id,
            'payment_terms' => 'credit',
            'lines' => [['description' => 'Big order', 'quantity' => 1, 'unit_price' => 50000]],
        ])->assertCreated();
        $id = $invoice->json('id');

        $approval = $this->withHeaders($headers)->postJson('/api/credit-approvals', ['invoice_id' => $id])
            ->assertCreated()->assertJsonPath('status', 'rejected');

        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertStatus(500);

        $this->withHeaders($headers)
            ->postJson("/api/credit-approvals/{$approval->json('id')}/override", ['notes' => 'Client relationship, approved by CFO'])
            ->assertOk()->assertJsonPath('status', 'overridden');

        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk()->assertJsonPath('status', 'raised');
    }

    // =========================================================================
    // ContractRetentionTerms cap enforcement - inclusive boundary
    // =========================================================================

    public function test_retention_cap_is_enforced_inclusive_across_multiple_invoices(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $this->withHeaders($headers)->postJson('/api/contract-retention-terms', [
            'contract_type' => 'sales_order', 'contract_id' => $salesOrder->id,
            'direction' => 'receivable', 'retention_percentage' => 0.10, 'retention_cap' => 500,
        ])->assertCreated();

        // Invoice 1: gross 4000, naive retention = 400 (10%), cap headroom 500 -> withheld 400.
        $inv1 = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Phase 1', 'quantity' => 1, 'unit_price' => 4000]],
        ])->assertCreated();
        $this->assertSame('400.00', $inv1->json('retention_amount'));
        $this->withHeaders($headers)->postJson("/api/invoices/{$inv1->json('id')}/raise")->assertOk();

        // Invoice 2: gross 4000, naive retention 400, but only 100 headroom left (500-400) -> withheld 100.
        $inv2 = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Phase 2', 'quantity' => 1, 'unit_price' => 4000]],
        ])->assertCreated();
        $this->assertSame('100.00', $inv2->json('retention_amount'));
        $this->withHeaders($headers)->postJson("/api/invoices/{$inv2->json('id')}/raise")->assertOk();

        // Invoice 3: cumulative now exactly at cap (500) - boundary is inclusive, withhold zero.
        $inv3 = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Phase 3', 'quantity' => 1, 'unit_price' => 4000]],
        ])->assertCreated();
        $this->assertSame('0.00', $inv3->json('retention_amount'));
    }

    // =========================================================================
    // Credit Note - proportional reversal
    // =========================================================================

    public function test_credit_note_issued_proportionally_reverses_invoice_and_reduces_retention(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $this->withHeaders($headers)->postJson('/api/contract-retention-terms', [
            'contract_type' => 'sales_order', 'contract_id' => $salesOrder->id,
            'direction' => 'receivable', 'retention_percentage' => 0.10,
        ])->assertCreated();

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Materials', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $id = $invoice->json('id');
        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk();

        $this->assertDatabaseHas('retention_accounts', [
            'tenant_id' => $this->tenant->id, 'invoice_id' => $id, 'direction' => 'receivable', 'amount_cents' => 116000,
        ]); // (1000 retention + 160 VAT on retention) * 100 cents

        $creditNote = $this->withHeaders($headers)->postJson('/api/credit-notes', [
            'invoice_id' => $id, 'proportion' => 0.5, 'reason' => 'price_adjustment',
        ])->assertCreated();
        $this->assertSame('5000.00', $creditNote->json('amount')); // 50% of gross 10000
        $this->assertSame('posted', $creditNote->json('status'));

        $entry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('id', $creditNote->json('journal_entry_id'))->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('5000.00', (string) $entry->amountFor('Revenue', 'debit'));

        $this->assertDatabaseHas('retention_accounts', [
            'tenant_id' => $this->tenant->id, 'invoice_id' => $id, 'amount_cents' => 58000, // halved
        ]);
    }

    // =========================================================================
    // Client-withheld WHT receipt
    // =========================================================================

    public function test_client_withheld_wht_receipt_settles_invoice_via_cash_plus_wht_credit(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $id = $invoice->json('id');
        $netPayable = $invoice->json('net_payable'); // 11600.00 (10000 + 16% VAT, no retention)
        $this->assertSame('11600.00', $netPayable);
        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk();

        $payment = $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'invoice_allocations' => [['invoice_id' => $id, 'amount' => 11600]],
            'wht_withheld_by_client' => 580, // 5% of net_payable, arbitrary for the test
            'wht_tax_code' => 'WHT_RESIDENT_5',
            'method' => 'bank_transfer',
        ])->assertCreated();
        $this->assertSame('11020.00', $payment->json('amount')); // cash actually received = net - wht
        $this->assertSame('580.00', $payment->json('wht_amount'));

        // cash-allocated (11600) + wht-credited (580) >= net_payable (11600) -> paid, per §3.9's
        // "settled once cash-allocated plus WHT-credited equals net_payable" rule.
        $this->withHeaders($headers)->getJson("/api/invoices/{$id}")->assertJsonPath('status', 'paid');

        $entry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->latest('id')->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('580.00', (string) $entry->amountFor('WHT Receivable/Tax Credit', 'debit'));
    }

    // =========================================================================
    // Customer Advance: received unallocated, applied to a new invoice, refunded
    // =========================================================================

    public function test_advance_payment_is_a_real_customer_advance_then_applied_and_refundable(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');

        $payment = $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'advance_amount' => 5000,
            'method' => 'bank_transfer',
        ])->assertCreated();

        $advanceEntry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('event_type', 'customer_advance_received')->with('lines.account')->first();
        $this->assertTrue($advanceEntry->isBalanced());
        $this->assertSame('5000.00', (string) $advanceEntry->amountFor('Customer Advance', 'credit'));

        $advanceAllocationId = $payment->json('allocations.0.id');
        $this->assertDatabaseHas('payment_allocations', ['id' => $advanceAllocationId, 'invoice_id' => null, 'status' => 'allocated']);

        // Apply it to a new invoice at raise time - net_payable (6960)
        // must exceed the advance (5000) for it to apply cleanly; a
        // larger advance than the invoice owes isn't this test's
        // scenario (that excess would need its own handling, not
        // exercised here).
        $salesOrder = $this->makeSalesOrder();
        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Windows', 'quantity' => 1, 'unit_price' => 6000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise", [
            'advance_payment_allocation_ids' => [$advanceAllocationId],
        ])->assertOk();

        $this->assertDatabaseHas('payment_allocations', ['id' => $advanceAllocationId, 'invoice_id' => $invoice->json('id')]);

        // A second, still-unused advance can be refunded.
        $payment2 = $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id, 'advance_amount' => 1000, 'method' => 'mpesa',
        ])->assertCreated();
        $secondAllocationId = $payment2->json('allocations.0.id');

        $refunded = $this->withHeaders($headers)->postJson("/api/payment-allocations/{$secondAllocationId}/refund")->assertOk();
        $this->assertSame('refunded', $refunded->json('status'));
    }

    // =========================================================================
    // Write-off
    // =========================================================================

    public function test_write_off_posts_bad_debt_expense_and_marks_invoice_written_off(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Insolvent client job', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $id = $invoice->json('id');
        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk();

        $writeOff = $this->withHeaders($headers)->postJson('/api/write-offs', [
            'invoice_id' => $id, 'amount' => 11600, 'reason' => 'Client placed into receivership',
        ])->assertCreated();
        $this->assertNotNull($writeOff->json('journal_entry_id'));

        $entry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('id', $writeOff->json('journal_entry_id'))->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('11600.00', (string) $entry->amountFor('Bad Debt Expense', 'debit'));

        $this->withHeaders($headers)->getJson("/api/invoices/{$id}")->assertJsonPath('status', 'written_off');
    }

    // =========================================================================
    // Retention release - both directions
    // =========================================================================

    public function test_retention_release_client_side_posts_correctly(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $this->withHeaders($headers)->postJson('/api/contract-retention-terms', [
            'contract_type' => 'sales_order', 'contract_id' => $salesOrder->id,
            'direction' => 'receivable', 'retention_percentage' => 0.10,
        ])->assertCreated();

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Final phase', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        $retentionAccount = \App\Models\RetentionAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('invoice_id', $invoice->json('id'))->first();

        $release = $this->withHeaders($headers)->postJson('/api/retention-releases', [
            'retention_account_id' => $retentionAccount->id, 'stage' => 'practical_completion', 'amount' => 1160,
        ])->assertCreated();
        $this->assertSame('pending', $release->json('status'));

        $this->withHeaders($headers)->postJson("/api/retention-releases/{$release->json('id')}/mark-ready")
            ->assertOk()->assertJsonPath('status', 'ready');

        $released = $this->withHeaders($headers)->postJson("/api/retention-releases/{$release->json('id')}/release")
            ->assertOk();
        $this->assertSame('released', $released->json('status'));

        $entry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('id', $released->json('journal_entry_id'))->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('1160.00', (string) $entry->amountFor('Retention Receivable', 'credit'));
    }

    public function test_early_retention_release_requires_a_reason(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $this->withHeaders($headers)->postJson('/api/contract-retention-terms', [
            'contract_type' => 'sales_order', 'contract_id' => $salesOrder->id,
            'direction' => 'receivable', 'retention_percentage' => 0.10,
        ])->assertCreated();
        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Early release job', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();
        $retentionAccount = \App\Models\RetentionAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('invoice_id', $invoice->json('id'))->first();
        $release = $this->withHeaders($headers)->postJson('/api/retention-releases', [
            'retention_account_id' => $retentionAccount->id, 'stage' => 'dlp_end', 'amount' => 1160,
        ])->assertCreated();

        // Still 'pending' (never marked ready) - releasing without a reason must fail.
        $this->withHeaders($headers)->postJson("/api/retention-releases/{$release->json('id')}/release")->assertStatus(500);

        $this->withHeaders($headers)->postJson("/api/retention-releases/{$release->json('id')}/release", [
            'early_release_reason' => 'Client requested early release ahead of full DLP elapsing',
        ])->assertOk()->assertJsonPath('status', 'released');
    }

    public function test_retention_release_subcontractor_side_posts_correctly(): void
    {
        $subcontract = Subcontract::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->supplier(), 'status' => 'active']);
        $claim = \App\Models\ProgressClaim::create([
            'tenant_id' => $this->tenant->id, 'subcontract_id' => $subcontract->id,
            'period' => '2026-09', 'amount_claimed_cents' => Money::fromMajor('10000'),
        ]);

        app(ProgressClaimService::class)->certify($claim, Money::fromMajor('10000'), '0.16', '0.10');

        $retentionAccount = \App\Models\RetentionAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('progress_claim_id', $claim->id)->firstOrFail();
        $this->assertSame('payable', $retentionAccount->direction);
        $this->assertSame('1160.00', (string) $retentionAccount->amount);

        $headers = $this->headersFor('finance@example.com', 'finance');
        $release = $this->withHeaders($headers)->postJson('/api/retention-releases', [
            'retention_account_id' => $retentionAccount->id, 'stage' => 'practical_completion', 'amount' => 1160,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/retention-releases/{$release->json('id')}/mark-ready")->assertOk();
        $released = $this->withHeaders($headers)->postJson("/api/retention-releases/{$release->json('id')}/release")->assertOk();

        $entry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('id', $released->json('journal_entry_id'))->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('1160.00', (string) $entry->amountFor('Retention Payable', 'debit'));
    }

    private function supplier(): int
    {
        return Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Steel & Co', 'type' => 'supplier',
            'tax_residency_status' => 'resident_certified',
        ])->id;
    }

    // =========================================================================
    // Sales Return financials (against an Invoice)
    // =========================================================================

    public function test_sales_return_financials_service_posts_correctly_against_an_invoice(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Cement bags', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $id = $invoice->json('id');
        $this->withHeaders($headers)->postJson("/api/invoices/{$id}/raise")->assertOk();

        $invoiceModel = Invoice::find($id);
        $entry = app(\App\Services\SalesReturnFinancialsService::class)->postAgainstInvoice(
            $invoiceModel, '0.2', Money::fromMajor('1600'), 'RETURN-1',
        );

        $this->assertTrue($entry->isBalanced());
        $this->assertSame('2000.00', (string) $entry->amountFor('Revenue', 'debit')); // 20% of gross 10000
        $this->assertSame('320.00', (string) $entry->amountFor('VAT Payable', 'debit')); // 20% of vat 1600
        $this->assertSame('2320.00', (string) $entry->amountFor('Accounts Receivable', 'credit')); // 20% of net_payable 11600
        $this->assertSame('1600.00', (string) $entry->amountFor('Inventory (FG)', 'debit'));
    }

    // =========================================================================
    // OpeningBalanceBatch - full migration
    // =========================================================================

    public function test_opening_balance_batch_full_migration_posts_and_balances(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');

        $batch = $this->withHeaders($headers)->postJson('/api/opening-balance-batches', [
            'as_of_date' => '2026-01-01',
        ])->assertCreated();
        $batchId = $batch->json('id');

        $salesOrder = $this->makeSalesOrder();
        $invoice = $this->withHeaders($headers)->postJson("/api/opening-balance-batches/{$batchId}/invoices", [
            'sales_order_id' => $salesOrder->id, 'party_id' => $this->customer->id,
            'invoice_date' => '2025-11-01', 'posting_date' => '2025-11-01', 'payment_terms' => 'credit',
            'gross_amount' => 100000, 'vat_amount' => 16000, 'retention_amount' => 10000,
            'net_payable' => 106000, 'status' => 'raised',
        ])->assertCreated();
        $this->assertTrue($invoice->json('is_opening_balance'));

        $payment = $this->withHeaders($headers)->postJson("/api/opening-balance-batches/{$batchId}/payments", [
            'party_id' => $this->customer->id, 'direction' => 'receipt', 'amount' => 60000,
            'method' => 'bank_transfer', 'received_at' => '2025-12-01', 'posting_date' => '2025-12-01',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/opening-balance-batches/{$batchId}/payment-allocations", [
            'payment_id' => $payment->json('id'), 'invoice_id' => $invoice->json('id'), 'amount_allocated' => 60000,
        ])->assertCreated();

        $item = \App\Models\Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'OPEN-STOCK-1', 'name' => 'Opening stock item',
            'category_id' => \App\Models\Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Opening Cat', 'valuation_method' => 'weighted_average'])->id,
            'type' => 'raw_material',
            'uom_id' => \App\Models\UnitOfMeasure::create(['tenant_id' => $this->tenant->id, 'code' => 'BAG', 'name' => 'Bag'])->id,
        ]);
        $warehouse = \App\Models\Warehouse::where('tenant_id', $this->tenant->id)->where('is_default', true)->first();
        $this->withHeaders($headers)->postJson("/api/opening-balance-batches/{$batchId}/stock", [
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 200, 'unit_cost' => 500,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/opening-balance-batches/{$batchId}/document-sequence", [
            'entity_type' => 'invoice', 'last_issued_number' => 542,
        ])->assertCreated();
        $this->assertDatabaseHas('document_sequences', [
            'tenant_id' => $this->tenant->id, 'entity_type' => 'invoice', 'next_number' => 543,
        ]);

        $posted = $this->withHeaders($headers)->postJson("/api/opening-balance-batches/{$batchId}/post", [
            'debit_balances' => ['Accounts Receivable' => 46000, 'Inventory (RM)' => 100000],
            'credit_balances' => ['Cash/Bank' => 60000],
        ])->assertOk();
        $this->assertSame('posted', $posted->json('status'));

        $entry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('id', $posted->json('journal_entry_id'))->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('opening_balance', $entry->event_type);
        // plug: debits 146000 - credits 60000 = 86000 credited to Retained Earnings (Opening)
        $this->assertSame('86000.00', (string) $entry->amountFor('Retained Earnings (Opening)', 'credit'));
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_invoice_is_tenant_isolated(): void
    {
        $salesOrder = $this->makeSalesOrder();
        $headers = $this->headersFor('finance@example.com', 'finance');

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Isolated', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'finance')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Finance', 'email' => 'other-finance@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => true,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->getJson("/api/invoices/{$invoice->json('id')}")->assertStatus(404);
    }
}
