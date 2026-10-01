<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialLine;
use App\Models\Category;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockValuationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * manufacturing branch (execution_plan.md): ProductionOrder consumes RM
 * per the BOM, produces FG at standard cost, and posts through
 * ledger-core's postProductionConsumption; QualityCheck proves its
 * polymorphic shape against ProductionOrder (GRN/StockQuarantine was
 * the first checkable_type, inventory-core); wastage variance against
 * BillOfMaterial.tolerance_pct is flagged when exceeded.
 */
class ManufacturingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Item $rawMaterial;

    private Item $finishedGood;

    private BillOfMaterial $bom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->warehouse = Warehouse::where('tenant_id', $this->tenant->id)->where('is_default', true)->first();

        $uom = UnitOfMeasure::create(['tenant_id' => $this->tenant->id, 'code' => 'PC', 'name' => 'Piece']);
        $rmCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Raw Materials', 'valuation_method' => 'fifo']);
        $fgCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Finished Goods', 'valuation_method' => 'standard_cost']);

        $this->rawMaterial = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'RM-STEEL', 'name' => 'Steel Bar',
            'category_id' => $rmCategory->id, 'type' => 'raw_material', 'uom_id' => $uom->id,
        ]);
        $this->finishedGood = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'FG-GATE', 'name' => 'Steel Gate',
            'category_id' => $fgCategory->id, 'type' => 'finished_good', 'uom_id' => $uom->id,
            'standard_cost_cents' => Money::fromMajor('1000'),
        ]);

        $this->bom = BillOfMaterial::create([
            'tenant_id' => $this->tenant->id, 'finished_good_item_id' => $this->finishedGood->id,
            'wastage_allowance_pct' => 0.05, 'tolerance_pct' => 0.10, 'source' => 'manual',
        ]);
        BillOfMaterialLine::create([
            'tenant_id' => $this->tenant->id, 'bill_of_materials_id' => $this->bom->id,
            'raw_material_item_id' => $this->rawMaterial->id, 'quantity' => 10, // 10 RM units per 1 FG unit
        ]);

        // Receive 1,000 units of RM at KES 50 each so production has stock to consume.
        StockLedger::create([
            'tenant_id' => $this->tenant->id, 'item_id' => $this->rawMaterial->id, 'warehouse_id' => $this->warehouse->id,
            'movement_type' => 'receipt', 'quantity' => 1000, 'unit_cost_cents' => Money::fromMajor('50'),
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

    public function test_production_order_consumes_rm_produces_fg_and_posts_a_balanced_ledger_entry(): void
    {
        $headers = $this->headersFor('warehouse@example.com', 'warehouse');

        $order = $this->withHeaders($headers)->postJson('/api/production-orders', [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10,
        ])->assertCreated();
        $this->assertSame('draft', $order->json('status'));

        $completed = $this->withHeaders($headers)
            ->postJson("/api/production-orders/{$order->json('id')}/complete")
            ->assertOk();

        $this->assertSame('completed', $completed->json('status'));
        // 10 FG units x 10 RM/unit x (1 + 5% wastage allowance) = 105 RM units, no actual override given.
        $this->assertSame('5250.00', $completed->json('rm_value_actual')); // 105 * KES 50
        $this->assertSame('10000.00', $completed->json('fg_value_standard')); // 10 * KES 1000
        $this->assertSame('4750.00', $completed->json('wastage_variance')); // favorable: standard - actual
        $this->assertFalse($completed->json('bom_variance_exceeded'));

        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $this->rawMaterial->id,
            'movement_type' => 'production_consumption', 'quantity' => '105.0000',
        ]);
        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $this->finishedGood->id,
            'movement_type' => 'production_output', 'quantity' => '10.0000',
        ]);

        $entry = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('id', $completed->json('journal_entry_id'))
            ->with('lines.account')->first();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('10000.00', (string) $entry->amountFor('Inventory (FG)', 'debit'));
        $this->assertSame('5250.00', (string) $entry->amountFor('Inventory (RM)', 'credit'));
        $this->assertSame('4750.00', (string) $entry->amountFor('Wastage Variance', 'credit'));

        $valuation = app(StockValuationService::class);
        $this->assertSame('895.0000', $valuation->onHandQuantity($this->rawMaterial, $this->warehouse)); // 1000 - 105
        $this->assertSame('10.0000', $valuation->onHandQuantity($this->finishedGood, $this->warehouse));

        // A ProductionOrder cannot be completed twice.
        $this->withHeaders($headers)->postJson("/api/production-orders/{$order->json('id')}/complete")->assertStatus(500);
    }

    public function test_actual_consumption_beyond_bom_tolerance_flags_bom_variance_exceeded(): void
    {
        $headers = $this->headersFor('warehouse@example.com', 'warehouse');

        $order = $this->withHeaders($headers)->postJson('/api/production-orders', [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10,
        ])->assertCreated();

        $lineId = $this->bom->lines()->first()->id;

        // Allowed (planned + 5% wastage allowance) is 105 units; tolerance
        // is 10% on top of that (115.5). Push actual consumption to 130 -
        // (130-105)/105 = ~23.8%, well past the 10% tolerance.
        $completed = $this->withHeaders($headers)
            ->postJson("/api/production-orders/{$order->json('id')}/complete", [
                'actual_quantities' => [(string) $lineId => 130],
            ])
            ->assertOk();

        $this->assertTrue($completed->json('bom_variance_exceeded'));
        $this->assertSame('6500.00', $completed->json('rm_value_actual')); // 130 * KES 50
    }

    public function test_quality_check_scrap_disposition_writes_off_finished_goods_and_reduces_stock(): void
    {
        $headers = $this->headersFor('warehouse@example.com', 'warehouse');

        $order = $this->withHeaders($headers)->postJson('/api/production-orders', [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 5,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/production-orders/{$order->json('id')}/complete")->assertOk();

        $valuation = app(StockValuationService::class);
        $this->assertSame('5.0000', $valuation->onHandQuantity($this->finishedGood, $this->warehouse));

        $qc = $this->withHeaders($headers)->postJson("/api/production-orders/{$order->json('id')}/quality-checks", [
            'result' => 'fail', 'disposition' => 'scrap',
        ])->assertCreated();
        $this->assertSame('fail', $qc->json('result'));

        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $this->finishedGood->id,
            'movement_type' => 'scrap', 'quantity' => '5.0000',
        ]);
        $this->assertSame('0.0000', $valuation->onHandQuantity($this->finishedGood, $this->warehouse));

        $writeOffEntry = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('event_type', 'rework_scrap_writeoff')
            ->with('lines.account')->first();
        $this->assertTrue($writeOffEntry->isBalanced());
        $this->assertSame('5000.00', (string) $writeOffEntry->amountFor('Scrap/Variance Expense', 'debit'));

        $this->withHeaders($headers)
            ->getJson("/api/production-orders/{$order->json('id')}")
            ->assertJsonPath('qc_status', 'failed');
    }

    public function test_finished_good_without_standard_cost_cannot_be_produced(): void
    {
        $headers = $this->headersFor('warehouse@example.com', 'warehouse');

        $rawFgCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'FIFO FG', 'valuation_method' => 'fifo']);
        $uom = UnitOfMeasure::where('tenant_id', $this->tenant->id)->first();
        $badFg = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'FG-BAD', 'name' => 'Non-standard FG',
            'category_id' => $rawFgCategory->id, 'type' => 'finished_good', 'uom_id' => $uom->id,
        ]);
        $badBom = BillOfMaterial::create([
            'tenant_id' => $this->tenant->id, 'finished_good_item_id' => $badFg->id,
            'wastage_allowance_pct' => 0.05, 'tolerance_pct' => 0.10, 'source' => 'manual',
        ]);
        BillOfMaterialLine::create([
            'tenant_id' => $this->tenant->id, 'bill_of_materials_id' => $badBom->id,
            'raw_material_item_id' => $this->rawMaterial->id, 'quantity' => 1,
        ]);

        $order = $this->withHeaders($headers)->postJson('/api/production-orders', [
            'bom_id' => $badBom->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/production-orders/{$order->json('id')}/complete")->assertStatus(500);
    }

    public function test_production_order_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('warehouse@example.com', 'warehouse');

        $order = $this->withHeaders($headers)->postJson('/api/production-orders', [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'warehouse')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Warehouse', 'email' => 'other-warehouse@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->getJson("/api/production-orders/{$order->json('id')}")->assertStatus(404);
    }
}
