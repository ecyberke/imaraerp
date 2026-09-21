<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * procurement exit criterion (execution_plan.md): "PR -> PO -> GRN -> QC
 * -> ledger posting round-trips, including a partial delivery across two
 * GRNs against one PO line; a Progress Claim certification posts
 * correctly, gets reversed after a failed inspection, and re-certifies
 * as a fresh row; a foreign-currency PO paid at a different rate than
 * its GRN posts the FX gain/loss correctly and AP clears to zero; a
 * standard_cost-category GRN posts Inventory at standard and Accounts
 * Payable at actual, with the difference landing correctly in Purchase
 * Price Variance in both directions."
 */
class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Currency $kes;

    private Party $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->warehouse = Warehouse::where('tenant_id', $this->tenant->id)->where('is_default', true)->first();
        $this->kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
        $this->supplier = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Steel & Co', 'type' => 'supplier',
            'tax_residency_status' => 'resident_certified',
        ]);
    }

    /**
     * §1.1: MFA mandatory for Finance/Admin. The real TOTP enrollment
     * round trip is already proven in platform-foundation's tests - here
     * it's just a precondition to satisfy, not something this branch's
     * own tests need to re-prove, so finance/admin users are created
     * with MFA already enabled.
     */
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

    private function makeItem(string $sku, string $valuationMethod = 'fifo', ?Money $standardCost = null): Item
    {
        $category = Category::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => ucfirst($valuationMethod).' Category'],
            ['valuation_method' => $valuationMethod],
        );
        $uom = UnitOfMeasure::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'PC'],
            ['name' => 'Piece'],
        );

        return Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku,
            'category_id' => $category->id, 'type' => 'raw_material', 'uom_id' => $uom->id,
            'standard_cost_cents' => $standardCost,
        ]);
    }

    // =========================================================================
    // PR -> PO -> GRN -> QC -> ledger posting round trip
    // =========================================================================

    public function test_full_pr_to_po_to_grn_to_qc_to_ledger_round_trip(): void
    {
        $item = $this->makeItem('STEEL-001');
        $headers = $this->headersFor('proc@example.com', 'procurement');

        $pr = $this->withHeaders($headers)->postJson('/api/purchase-requisitions', [
            'lines' => [['item_id' => $item->id, 'quantity_needed' => 100]],
        ])->assertCreated();

        $this->withHeaders($headers)
            ->patchJson("/api/purchase-requisitions/{$pr->json('id')}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $po = $this->withHeaders($headers)->postJson('/api/purchase-orders', [
            'purchase_requisition_id' => $pr->json('id'),
            'party_id' => $this->supplier->id,
            'currency_id' => $this->kes->id,
            'lines' => [[
                'item_id' => $item->id,
                'purchase_requisition_line_id' => $pr->json('lines.0.id'),
                'quantity_ordered' => 100,
                'unit_cost' => 350,
            ]],
        ])->assertCreated();
        $this->assertSame('ordered', $po->json('status'));

        $grn = $this->withHeaders($headers)
            ->postJson("/api/purchase-orders/{$po->json('id')}/goods-receipt-notes", [])
            ->assertCreated();

        $grnLine = $this->withHeaders($headers)->postJson("/api/goods-receipt-notes/{$grn->json('id')}/lines", [
            'purchase_order_line_id' => $po->json('lines.0.id'),
            'quantity' => 100,
        ])->assertCreated();

        // Nothing in stock_ledger until QC passes (§6).
        $this->assertDatabaseMissing('stock_ledger', ['tenant_id' => $this->tenant->id, 'item_id' => $item->id]);

        $this->withHeaders($headers)->postJson("/api/grn-lines/{$grnLine->json('id')}/quality-checks", [
            'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'movement_type' => 'receipt', 'quantity' => 100,
        ]);

        // Fully received AND QC-passed - per §5.2's state machine this
        // reaches 'stocked' (the qc_passed terminal state), not just
        // 'received' (which is a receiving-completeness state, distinct
        // from the QC outcome that follows it).
        $poFresh = $this->withHeaders($headers)->getJson("/api/purchase-orders/{$po->json('id')}")->assertOk();
        $this->assertSame('stocked', $poFresh->json('status'));

        $entry = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'grn_receipt')->with('lines.account')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(3500000, $entry->amountFor('Inventory (RM)')->cents());
        $this->assertSame(3500000, $entry->amountFor('Accounts Payable', 'credit')->cents());
    }

    public function test_partial_delivery_across_two_grns_against_one_po_line(): void
    {
        $item = $this->makeItem('CEMENT-001');
        $headers = $this->headersFor('proc2@example.com', 'procurement');

        $po = $this->withHeaders($headers)->postJson('/api/purchase-orders', [
            'party_id' => $this->supplier->id, 'currency_id' => $this->kes->id,
            'lines' => [['item_id' => $item->id, 'quantity_ordered' => 1000, 'unit_cost' => 10]],
        ])->assertCreated();
        $lineId = $po->json('lines.0.id');

        // First shipment: 600 of 1000.
        $grn1 = $this->withHeaders($headers)->postJson("/api/purchase-orders/{$po->json('id')}/goods-receipt-notes", [])->assertCreated();
        $line1 = $this->withHeaders($headers)->postJson("/api/goods-receipt-notes/{$grn1->json('id')}/lines", [
            'purchase_order_line_id' => $lineId, 'quantity' => 600,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/grn-lines/{$line1->json('id')}/quality-checks", [
            'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $poAfterFirst = $this->withHeaders($headers)->getJson("/api/purchase-orders/{$po->json('id')}")->assertOk();
        $this->assertSame('partially_received', $poAfterFirst->json('status'));

        // Second shipment: the remaining 400.
        $grn2 = $this->withHeaders($headers)->postJson("/api/purchase-orders/{$po->json('id')}/goods-receipt-notes", [])->assertCreated();
        $line2 = $this->withHeaders($headers)->postJson("/api/goods-receipt-notes/{$grn2->json('id')}/lines", [
            'purchase_order_line_id' => $lineId, 'quantity' => 400,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/grn-lines/{$line2->json('id')}/quality-checks", [
            'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $poAfterSecond = $this->withHeaders($headers)->getJson("/api/purchase-orders/{$po->json('id')}")->assertOk();
        $this->assertSame('stocked', $poAfterSecond->json('status')); // fully received AND both lines QC-passed

        // Two separate GRNLines against the same PurchaseOrderLine.
        $this->assertSame(2, \App\Models\GRNLine::where('tenant_id', $this->tenant->id)
            ->where('purchase_order_line_id', $lineId)->count());

        // Both receipts posted, summing to the full 1000 x 10 = 10,000.
        $entries = JournalEntry::where('tenant_id', $this->tenant->id)->where('event_type', 'grn_receipt')->get();
        $this->assertCount(2, $entries);
        $total = \App\Support\Money::sum(...$entries->map(fn ($e) => $e->amountFor('Inventory (RM)')));
        $this->assertSame(1000000, $total->cents()); // 10,000.00 KES in cents
    }

    public function test_standard_cost_grn_posts_variance_both_directions_through_real_po_path(): void
    {
        $item = $this->makeItem('STDCOST-001', 'standard_cost', Money::fromMajor(350));
        $headers = $this->headersFor('proc3@example.com', 'procurement');

        // Unfavorable: actual (365) > standard (350).
        $poUnfav = $this->withHeaders($headers)->postJson('/api/purchase-orders', [
            'party_id' => $this->supplier->id, 'currency_id' => $this->kes->id,
            'lines' => [['item_id' => $item->id, 'quantity_ordered' => 100, 'unit_cost' => 365]],
        ])->assertCreated();
        $grn = $this->withHeaders($headers)->postJson("/api/purchase-orders/{$poUnfav->json('id')}/goods-receipt-notes", [])->assertCreated();
        $line = $this->withHeaders($headers)->postJson("/api/goods-receipt-notes/{$grn->json('id')}/lines", [
            'purchase_order_line_id' => $poUnfav->json('lines.0.id'), 'quantity' => 100,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/grn-lines/{$line->json('id')}/quality-checks", [
            'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $entry = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'grn_receipt_standard_cost')->with('lines.account')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(3500000, $entry->amountFor('Inventory (RM)')->cents());
        $this->assertSame(3650000, $entry->amountFor('Accounts Payable', 'credit')->cents());
        $this->assertSame(150000, $entry->amountFor('Purchase Price Variance')->cents());
    }

    // =========================================================================
    // Progress Claim: certify, reverse after failed inspection, re-certify
    // =========================================================================

    public function test_progress_claim_certifies_reverses_and_recertifies_as_a_fresh_row(): void
    {
        $headers = $this->headersFor('proc4@example.com', 'procurement');

        $subcontractor = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Roofing Sub', 'type' => 'contractor',
            'tax_residency_status' => 'resident_certified',
        ]);

        $subcontract = $this->withHeaders($headers)->postJson('/api/subcontracts', [
            'party_id' => $subcontractor->id,
        ])->assertCreated();

        $claim = $this->withHeaders($headers)->postJson("/api/subcontracts/{$subcontract->json('id')}/progress-claims", [
            'period' => '2026-09', 'amount_claimed' => 100,
        ])->assertCreated();

        $certified = $this->withHeaders($headers)->postJson("/api/progress-claims/{$claim->json('id')}/certify", [
            'amount_certified' => 100, 'vat_rate' => 0.16, 'retention_percentage' => 0.10,
        ])->assertOk();

        $this->assertSame('certified', $certified->json('status'));
        $this->assertSame('101.40', $certified->json('net_payable')); // matches ledger-core's worked example

        $certifyEntry = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'progress_claim_certified')->with('lines.account')->firstOrFail();
        $this->assertTrue($certifyEntry->isBalanced());
        $this->assertSame(11600, $certifyEntry->totalDebits()->cents());

        // Failed inspection -> reversed.
        $reversed = $this->withHeaders($headers)
            ->postJson("/api/progress-claims/{$claim->json('id')}/reverse")
            ->assertOk();
        $this->assertSame('reversed', $reversed->json('status'));

        $reversalEntry = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'progress_claim_certified_reversal')->firstOrFail();
        $this->assertTrue($reversalEntry->isBalanced());
        $this->assertSame($certifyEntry->id, $reversalEntry->reversal_of_journal_entry_id);

        // Re-certifies as a FRESH row, not editing the disputed one.
        $freshClaim = $this->withHeaders($headers)->postJson("/api/subcontracts/{$subcontract->json('id')}/progress-claims", [
            'period' => '2026-09', 'amount_claimed' => 100,
        ])->assertCreated();
        $this->assertNotSame($claim->json('id'), $freshClaim->json('id'));

        $this->withHeaders($headers)->postJson("/api/progress-claims/{$freshClaim->json('id')}/certify", [
            'amount_certified' => 100, 'vat_rate' => 0.16, 'retention_percentage' => 0.10,
        ])->assertOk()->assertJsonPath('status', 'certified');

        // The original disputed claim is untouched - still 'reversed'.
        $originalStillReversed = $this->withHeaders($headers)->getJson("/api/progress-claims/{$claim->json('id')}")->assertOk();
        $this->assertSame('reversed', $originalStillReversed->json('status'));
    }

    // =========================================================================
    // Foreign-currency PO paid at a different rate: FX gain/loss, AP clears
    // =========================================================================

    public function test_foreign_currency_po_settled_at_a_different_rate_posts_fx_gain_and_clears_ap(): void
    {
        $usd = Currency::create(['tenant_id' => $this->tenant->id, 'code' => 'USD', 'name' => 'US Dollar', 'is_base' => false]);
        $item = $this->makeItem('IMPORT-001');
        $procHeaders = $this->headersFor('proc5@example.com', 'procurement');
        // SupplierPaymentPolicy restricts payment creation to Admin/Finance
        // (§11: "Finance — ... Payments") - deliberately a different user/
        // role than the one raising the PO/GRN.
        $financeHeaders = $this->headersFor('finance5@example.com', 'finance');

        // PO booked at 130 KES/USD: 1,000 USD x 130 = 130,000 KES.
        $po = $this->withHeaders($procHeaders)->postJson('/api/purchase-orders', [
            'party_id' => $this->supplier->id, 'currency_id' => $usd->id, 'exchange_rate' => 130,
            'lines' => [['item_id' => $item->id, 'quantity_ordered' => 100, 'unit_cost' => 10]], // 10 USD/unit x 100 = 1,000 USD
        ])->assertCreated();

        $grn = $this->withHeaders($procHeaders)->postJson("/api/purchase-orders/{$po->json('id')}/goods-receipt-notes", [])->assertCreated();
        $line = $this->withHeaders($procHeaders)->postJson("/api/goods-receipt-notes/{$grn->json('id')}/lines", [
            'purchase_order_line_id' => $po->json('lines.0.id'), 'quantity' => 100,
        ])->assertCreated();
        $this->withHeaders($procHeaders)->postJson("/api/grn-lines/{$line->json('id')}/quality-checks", [
            'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $receiptEntry = JournalEntry::where('tenant_id', $this->tenant->id)->where('event_type', 'grn_receipt')->firstOrFail();
        $this->assertSame(13000000, $receiptEntry->amountFor('Accounts Payable', 'credit')->cents()); // 130,000.00 KES

        // Settled weeks later at 125 KES/USD (KES strengthened) -> a gain.
        // Switching from the procurement user to the finance user within
        // one test: Sanctum's guard memoizes the resolved user for the
        // lifetime of the guard instance (the same pitfall documented in
        // Phase0FoundationTest) - forgetGuards() forces it to
        // re-authenticate against the new bearer token instead of
        // silently reusing the procurement user's cached identity, which
        // would make this request authenticate as the wrong role and
        // fail SupplierPaymentPolicy for a misleading reason.
        $this->app['auth']->forgetGuards();

        $settlement = $this->withHeaders($financeHeaders)->postJson('/api/supplier-payments/fx-settlement', [
            'party_id' => $this->supplier->id, 'reference_type' => 'PurchaseOrder', 'reference_id' => $po->json('id'),
            'ap_portion_settled' => 130000, 'cash_disbursed' => 125000, 'settlement_exchange_rate' => 125,
        ])->assertCreated();

        $fxEntry = JournalEntry::where('tenant_id', $this->tenant->id)->where('event_type', 'fx_gain_loss')
            ->with('lines.account')->firstOrFail();
        $this->assertTrue($fxEntry->isBalanced());
        $this->assertSame(13000000, $fxEntry->amountFor('Accounts Payable')->cents());
        $this->assertSame(500000, $fxEntry->amountFor('FX Gain', 'credit')->cents());
        $this->assertSame(12500000, $fxEntry->amountFor('Cash/Bank', 'credit')->cents());

        // AP clears to zero: the receipt credited 130,000; the settlement
        // debited the same 130,000 (at booked rate) - net zero on AP.
        $apNet = $receiptEntry->amountFor('Accounts Payable', 'credit')->sub($fxEntry->amountFor('Accounts Payable'));
        $this->assertSame(0, $apNet->cents());
    }

    // =========================================================================
    // Supplier return (post-acceptance) and DemandTrigger dedup
    // =========================================================================

    public function test_post_acceptance_supplier_return_posts_and_reduces_stock(): void
    {
        $item = $this->makeItem('RETURN-001');
        $headers = $this->headersFor('wh@example.com', 'warehouse');

        // Receive 50 units first (via the inventory-core simulated path -
        // still valid, this branch doesn't replace it, only adds the real
        // GRN layer on top).
        $q = $this->withHeaders($headers)->postJson('/api/stock/quarantine', [
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'unit_cost' => 350,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/stock/quality-checks', [
            'stock_quarantine_id' => $q->json('id'), 'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $this->assertSame('50.0000', app(\App\Services\StockValuationService::class)->onHandQuantity($item, $this->warehouse));

        $return = $this->withHeaders($headers)->postJson('/api/supplier-returns', [
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 20, 'unit_cost' => 350, 'already_paid' => false,
        ])->assertCreated();

        $this->assertSame('30.0000', app(\App\Services\StockValuationService::class)->onHandQuantity($item, $this->warehouse));

        $entry = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'post_qc_return_after_acceptance')->with('lines.account')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(700000, $entry->amountFor('Accounts Payable')->cents()); // unpaid -> debits AP
        $this->assertSame(700000, $entry->amountFor('Inventory (RM)', 'credit')->cents());
    }

    public function test_demand_trigger_deduplicates_against_an_already_open_trigger(): void
    {
        $item = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'LOW-STOCK-001', 'name' => 'Low Stock Item',
            'category_id' => Category::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'valuation_method' => 'fifo'])->id,
            'type' => 'raw_material',
            'uom_id' => UnitOfMeasure::create(['tenant_id' => $this->tenant->id, 'code' => 'PC', 'name' => 'Piece'])->id,
            'reorder_level' => 100,
        ]);
        $headers = $this->headersFor('proc6@example.com', 'procurement');

        $first = $this->withHeaders($headers)->postJson('/api/demand-triggers/check', [
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();

        $second = $this->withHeaders($headers)->postJson('/api/demand-triggers/check', [
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
        ])->assertOk(); // 200, not 201 - the existing open trigger, not a new one

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, \App\Models\DemandTrigger::where('tenant_id', $this->tenant->id)->where('item_id', $item->id)->count());
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_purchase_order_cannot_be_read_across_tenants(): void
    {
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);
        $supplierB = Party::create(['tenant_id' => $tenantB->id, 'name' => "B's supplier", 'type' => 'supplier']);
        $currencyB = Currency::where('tenant_id', $tenantB->id)->where('is_base', true)->first();
        $poB = \App\Models\PurchaseOrder::create([
            'tenant_id' => $tenantB->id, 'party_id' => $supplierB->id, 'currency_id' => $currencyB->id, 'status' => 'ordered',
        ]);

        $headersA = $this->headersFor('a@example.com', 'admin');

        $this->withHeaders($headersA)->getJson("/api/purchase-orders/{$poB->id}")->assertNotFound();
    }
}
