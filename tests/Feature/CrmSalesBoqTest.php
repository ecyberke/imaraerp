<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Item;
use App\Models\Party;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\Subcontract;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * crm-sales-boq exit criterion (execution_plan.md): "a Quotation can be
 * created, pass Feasibility, select a supply path, and (for Direct Sale)
 * reserve stock with real line items against inventory-core; a BOQ
 * upload lands in staging and only becomes a live BOQLine after explicit
 * confirmation."
 */
class CrmSalesBoqTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Currency $kes;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->warehouse = Warehouse::where('tenant_id', $this->tenant->id)->where('is_default', true)->first();
        $this->kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
        $this->customer = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jengo Ltd', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
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

    private function makeItem(string $sku, string $valuationMethod = 'standard_cost', string $standardCost = '500'): Item
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
            'category_id' => $category->id, 'type' => 'finished_good', 'uom_id' => $uom->id,
            'standard_cost_cents' => \App\Support\Money::fromMajor($standardCost),
        ]);
    }

    private function receiveStock(Item $item, string $quantity, string $unitCost): void
    {
        StockLedger::create([
            'tenant_id' => $this->tenant->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => 'receipt',
            'quantity' => $quantity,
            'unit_cost_cents' => \App\Support\Money::fromMajor($unitCost),
        ]);
    }

    // =========================================================================
    // Exit criterion: Quotation -> Feasibility -> supply path -> reserve stock
    // =========================================================================

    public function test_quotation_passes_feasibility_and_reserves_real_stock_for_direct_sale(): void
    {
        $item = $this->makeItem('CEMENT-50KG');
        $this->receiveStock($item, '200', '500');

        $headers = $this->headersFor('sales@example.com', 'sales');

        $salesOrder = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id,
            'currency_id' => $this->kes->id,
            'supply_path' => 'direct_sale',
            'invoice_policy' => 'on_delivery',
            'lines' => [[
                'item_id' => $item->id,
                'description' => '50kg cement bags',
                'quantity' => 120,
                'rate' => 750,
            ]],
        ])->assertCreated();
        $this->assertSame('draft', $salesOrder->json('status'));
        $this->assertSame(1, count($salesOrder->json('lines')));

        $id = $salesOrder->json('id');

        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$id}/submit-for-feasibility")
            ->assertOk()
            ->assertJsonPath('status', 'feasibility_check');

        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$id}/feasibility-assessments", ['result' => 'passed'])
            ->assertCreated();

        $this->withHeaders($headers)->getJson("/api/sales-orders/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('feasibility_status', 'passed');

        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$id}/reserve-stock", ['warehouse_id' => $this->warehouse->id])
            ->assertOk();

        $this->assertDatabaseHas('stock_reservations', [
            'tenant_id' => $this->tenant->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'reserve_type' => 'hard',
            'status' => 'active',
            'quantity' => '120.0000',
            'reference_type' => \App\Models\SalesOrder::class,
            'reference_id' => $id,
        ]);

        // Available quantity is real inventory-core computation: 200 on
        // hand minus 120 now hard-reserved.
        $this->withHeaders($headers)
            ->getJson("/api/stock/availability?item_id={$item->id}&warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonPath('available_quantity', '80.0000');
    }

    public function test_feasibility_rejection_is_terminal_and_renegotiation_loops_back(): void
    {
        $item = $this->makeItem('REBAR-12MM');
        $headers = $this->headersFor('sales@example.com', 'sales');

        $rejected = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id, 'currency_id' => $this->kes->id,
            'supply_path' => 'direct_sale', 'invoice_policy' => 'on_order',
            'lines' => [['item_id' => $item->id, 'description' => 'Rebar', 'quantity' => 10, 'rate' => 1200]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$rejected->json('id')}/submit-for-feasibility")->assertOk();
        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$rejected->json('id')}/feasibility-assessments", ['result' => 'rejected'])
            ->assertCreated();
        $this->withHeaders($headers)->getJson("/api/sales-orders/{$rejected->json('id')}")
            ->assertJsonPath('status', 'rejected');

        $renegotiated = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id, 'currency_id' => $this->kes->id,
            'supply_path' => 'direct_sale', 'invoice_policy' => 'on_order',
            'lines' => [['item_id' => $item->id, 'description' => 'Rebar', 'quantity' => 10, 'rate' => 1200]],
        ])->assertCreated();
        $id = $renegotiated->json('id');
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/submit-for-feasibility")->assertOk();
        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$id}/feasibility-assessments", ['result' => 'renegotiating'])
            ->assertCreated();
        $this->withHeaders($headers)->getJson("/api/sales-orders/{$id}")->assertJsonPath('status', 'renegotiating');

        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/resubmit")
            ->assertOk()->assertJsonPath('status', 'feasibility_check');

        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$id}/feasibility-assessments", ['result' => 'passed'])
            ->assertCreated();
        $this->withHeaders($headers)->getJson("/api/sales-orders/{$id}")->assertJsonPath('status', 'approved');
    }

    public function test_reserve_stock_rejected_for_project_supply_path(): void
    {
        $item = $this->makeItem('BLOCK-6IN');
        $headers = $this->headersFor('sales@example.com', 'sales');

        $so = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id, 'currency_id' => $this->kes->id,
            'supply_path' => 'project', 'invoice_policy' => 'on_milestone',
            'lines' => [['item_id' => $item->id, 'description' => 'Blocks', 'quantity' => 500, 'rate' => 60]],
        ])->assertCreated();
        $id = $so->json('id');
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/submit-for-feasibility")->assertOk();
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/feasibility-assessments", ['result' => 'passed'])->assertCreated();

        $this->withHeaders($headers)
            ->postJson("/api/sales-orders/{$id}/reserve-stock", ['warehouse_id' => $this->warehouse->id])
            ->assertStatus(500); // DomainException: reservation only applies to direct_sale/manufacture_for_sale
    }

    // =========================================================================
    // Delivery / SalesReturn: real StockLedger issue + restock, COGS, rollup
    // =========================================================================

    public function test_delivery_issues_stock_posts_cogs_and_rolls_up_sales_order_status(): void
    {
        $item = $this->makeItem('CEMENT-50KG', 'standard_cost', '500');
        $this->receiveStock($item, '100', '500');
        $headers = $this->headersFor('sales@example.com', 'sales');

        $so = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id, 'currency_id' => $this->kes->id,
            'supply_path' => 'direct_sale', 'invoice_policy' => 'on_delivery',
            'lines' => [['item_id' => $item->id, 'description' => 'Cement', 'quantity' => 40, 'rate' => 750]],
        ])->assertCreated();
        $id = $so->json('id');
        $lineId = $so->json('lines.0.id');
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/submit-for-feasibility")->assertOk();
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/feasibility-assessments", ['result' => 'passed'])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/reserve-stock", ['warehouse_id' => $this->warehouse->id])->assertOk();

        $delivery = $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/deliveries", [
            'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();
        $deliveryId = $delivery->json('id');

        // Partial delivery first (25 of 40) - COGS is 25 * KES 500 standard cost.
        $this->withHeaders($headers)->postJson("/api/deliveries/{$deliveryId}/lines", [
            'sales_order_line_id' => $lineId, 'quantity_delivered' => 25,
        ])->assertCreated()->assertJsonPath('cogs_value', '12500.00');

        $this->withHeaders($headers)->postJson("/api/deliveries/{$deliveryId}/mark-delivered")->assertOk();
        $this->withHeaders($headers)->getJson("/api/sales-orders/{$id}")->assertJsonPath('status', 'partially_delivered');

        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'movement_type' => 'issue', 'quantity' => '25.0000',
        ]);

        // Hard reservation partially consumed: 40 reserved, 25 issued -> 15 remain active.
        $this->assertDatabaseHas('stock_reservations', [
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'status' => 'active', 'quantity' => '15.0000',
        ]);

        // Complete the delivery.
        $delivery2 = $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/deliveries", [
            'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/deliveries/{$delivery2->json('id')}/lines", [
            'sales_order_line_id' => $lineId, 'quantity_delivered' => 15,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/deliveries/{$delivery2->json('id')}/mark-delivered")->assertOk();

        $this->withHeaders($headers)->getJson("/api/sales-orders/{$id}")->assertJsonPath('status', 'delivered');

        $this->assertDatabaseHas('stock_reservations', [
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'status' => 'consumed', 'quantity' => '0.0000',
        ]);
    }

    public function test_sales_return_restocks_inventory_via_real_ledger_row(): void
    {
        $item = $this->makeItem('CEMENT-50KG', 'standard_cost', '500');
        $this->receiveStock($item, '100', '500');
        $headers = $this->headersFor('sales@example.com', 'sales');

        $so = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id, 'currency_id' => $this->kes->id,
            'supply_path' => 'direct_sale', 'invoice_policy' => 'on_delivery',
            'lines' => [['item_id' => $item->id, 'description' => 'Cement', 'quantity' => 10, 'rate' => 750]],
        ])->assertCreated();
        $id = $so->json('id');
        $lineId = $so->json('lines.0.id');
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/submit-for-feasibility")->assertOk();
        $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/feasibility-assessments", ['result' => 'passed'])->assertCreated();

        $delivery = $this->withHeaders($headers)->postJson("/api/sales-orders/{$id}/deliveries", [
            'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/deliveries/{$delivery->json('id')}/lines", [
            'sales_order_line_id' => $lineId, 'quantity_delivered' => 10,
        ])->assertCreated();

        $onHandBefore = \App\Models\StockLedger::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('item_id', $item->id)->count();

        $this->withHeaders($headers)->postJson("/api/deliveries/{$delivery->json('id')}/sales-returns", [
            'sales_order_line_id' => $lineId, 'quantity_returned' => 3, 'reason' => 'client over-ordered',
        ])->assertCreated()->assertJsonPath('restock_status', 'restocked');

        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'movement_type' => 'return', 'quantity' => '3.0000',
        ]);
        $this->assertSame(
            $onHandBefore + 1,
            \App\Models\StockLedger::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('item_id', $item->id)->count(),
        );
    }

    // =========================================================================
    // Exit criterion: BOQ upload lands in staging, only becomes a live
    // BOQLine after explicit confirmation
    // =========================================================================

    public function test_boq_upload_lands_in_staging_and_only_becomes_boq_line_after_confirmation(): void
    {
        $item = $this->makeItem('CONCRETE-M20');
        $subcontract = Subcontract::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'status' => 'active',
        ]);
        $headers = $this->headersFor('sales@example.com', 'sales');

        $boq = $this->withHeaders($headers)->postJson('/api/boqs', [
            'boqable_type' => 'subcontract', 'boqable_id' => $subcontract->id, 'uses_sections' => true,
            'source' => 'uploaded',
        ])->assertCreated();
        $boqId = $boq->json('id');

        $staged = $this->withHeaders($headers)->postJson('/api/boq-import-stagings', [
            'boq_id' => $boqId,
            'rows' => [
                ['description' => 'Excavation', 'unit' => 'm3', 'raw_quantity' => '120', 'raw_rate' => '850'],
                ['description' => 'M20 Concrete', 'unit' => 'm3', 'raw_quantity' => '45', 'raw_rate' => '9200'],
            ],
        ])->assertCreated();
        $this->assertSame(2, count($staged->json()));
        $this->assertSame('pending_review', $staged->json('0.status'));

        // Not yet a BoqLine - only staged.
        $this->assertDatabaseCount('boq_lines', 0);

        $stagingId = $staged->json('1.id');

        $this->withHeaders($headers)->patchJson("/api/boq-import-stagings/{$stagingId}/map", [
            'item_id' => $item->id, 'section' => 'Substructure', 'quantity' => 45, 'rate' => 9200,
        ])->assertOk()->assertJsonPath('status', 'pending_review');

        $line = $this->withHeaders($headers)->postJson("/api/boq-import-stagings/{$stagingId}/confirm", [
            'boq_id' => $boqId,
        ])->assertCreated();

        $this->assertDatabaseCount('boq_lines', 1);
        $this->assertSame('45.0000', $line->json('quantity'));
        $this->assertSame('9200.00', $line->json('rate'));
        $this->assertSame('414000.00', $line->json('amount'));

        $confirmedRow = \App\Models\BoqImportStaging::find($stagingId);
        $this->assertSame('confirmed', $confirmedRow->status);
        $this->assertSame($line->json('id'), $confirmedRow->boq_line_id);
    }

    public function test_boq_import_staging_row_cannot_be_confirmed_unmapped_or_twice(): void
    {
        $subcontract = Subcontract::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'status' => 'active',
        ]);
        $headers = $this->headersFor('sales@example.com', 'sales');

        $boq = $this->withHeaders($headers)->postJson('/api/boqs', [
            'boqable_type' => 'subcontract', 'boqable_id' => $subcontract->id,
        ])->assertCreated();

        $staged = $this->withHeaders($headers)->postJson('/api/boq-import-stagings', [
            'boq_id' => $boq->json('id'),
            'rows' => [['description' => 'Formwork', 'unit' => 'm2', 'raw_quantity' => '80', 'raw_rate' => '600']],
        ])->assertCreated();
        $stagingId = $staged->json('0.id');

        // Unmapped - confirm must fail (DomainException -> 500, since no
        // dedicated exception handler was added for this branch).
        $this->withHeaders($headers)->postJson("/api/boq-import-stagings/{$stagingId}/confirm", [
            'boq_id' => $boq->json('id'),
        ])->assertStatus(500);

        $this->withHeaders($headers)->patchJson("/api/boq-import-stagings/{$stagingId}/map", [
            'section' => 'Formwork', 'quantity' => 80, 'rate' => 600,
        ])->assertOk();

        $this->withHeaders($headers)->postJson("/api/boq-import-stagings/{$stagingId}/confirm", [
            'boq_id' => $boq->json('id'),
        ])->assertCreated();

        // Already confirmed - a second confirm attempt must fail.
        $this->withHeaders($headers)->postJson("/api/boq-import-stagings/{$stagingId}/confirm", [
            'boq_id' => $boq->json('id'),
        ])->assertStatus(500);
    }

    public function test_boq_import_staging_row_can_be_rejected(): void
    {
        $subcontract = Subcontract::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'status' => 'active',
        ]);
        $headers = $this->headersFor('sales@example.com', 'sales');

        $boq = $this->withHeaders($headers)->postJson('/api/boqs', [
            'boqable_type' => 'subcontract', 'boqable_id' => $subcontract->id,
        ])->assertCreated();

        $staged = $this->withHeaders($headers)->postJson('/api/boq-import-stagings', [
            'boq_id' => $boq->json('id'),
            'rows' => [['description' => 'Bad row', 'unit' => 'm2', 'raw_quantity' => '1', 'raw_rate' => '1']],
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/boq-import-stagings/{$staged->json('0.id')}/reject")
            ->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertDatabaseCount('boq_lines', 0);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_sales_order_is_tenant_isolated(): void
    {
        $item = $this->makeItem('PAINT-4L');
        $headers = $this->headersFor('sales@example.com', 'sales');

        $so = $this->withHeaders($headers)->postJson('/api/sales-orders', [
            'party_id' => $this->customer->id, 'currency_id' => $this->kes->id,
            'supply_path' => 'direct_sale', 'invoice_policy' => 'on_order',
            'lines' => [['item_id' => $item->id, 'description' => 'Paint', 'quantity' => 5, 'rate' => 1500]],
        ])->assertCreated();

        // forgetGuards(): the prior authenticated request left Sanctum's
        // guard memoized to tenant1's user for the rest of this test
        // method (a Laravel feature-test artifact, not real HTTP
        // isolation) - without this, TenantScope on Role resolves via
        // that stale guard and silently conflicts with the explicit
        // ->where('tenant_id', $otherTenant->id) below, returning null.
        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'sales')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Sales', 'email' => 'other-sales@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        // TenantScope applies to route-model-binding itself, so a
        // cross-tenant lookup never reaches SalesOrderPolicy::view at all
        // - it 404s before authorization runs, the strongest form of
        // isolation (the other tenant can't even confirm the row exists).
        $this->withHeaders($otherHeaders)->getJson("/api/sales-orders/{$so->json('id')}")->assertStatus(404);
    }
}
