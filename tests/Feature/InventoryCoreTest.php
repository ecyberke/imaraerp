<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Item;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockAvailabilityService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * inventory-core exit criterion (execution_plan.md): "a GRN receipt -> QC
 * pass -> stock_ledger insert -> availability computation round-trip
 * works and posts correctly through ledger-core; two simulated
 * concurrent reservations against the last unit of an item resolve
 * correctly (one succeeds, one gets a clear retry, never both)."
 *
 * GoodsReceiptNote/SalesOrderReservation don't exist yet (procurement/
 * crm-sales-boq) - see StockReceivingService/the stock_reservations
 * migration for why this simulates the same inputs those entities will
 * eventually supply, rather than waiting on them.
 */
class InventoryCoreTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Item $item;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'General', 'valuation_method' => 'fifo']);
        $uom = UnitOfMeasure::create(['tenant_id' => $this->tenant->id, 'code' => 'PC', 'name' => 'Piece']);
        $this->item = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'ITM-001', 'name' => 'Test Item',
            'category_id' => $category->id, 'type' => 'raw_material', 'uom_id' => $uom->id,
        ]);
        $this->warehouse = Warehouse::where('tenant_id', $this->tenant->id)->where('is_default', true)->first();
    }

    private function makeUser(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_tenant_is_seeded_with_main_and_in_transit_warehouses(): void
    {
        $warehouses = Warehouse::where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(2, $warehouses);
        $this->assertTrue($warehouses->firstWhere('is_default', true)->name === 'Main Warehouse');
        $this->assertTrue($warehouses->firstWhere('is_in_transit', true)->name === 'In Transit');
    }

    // =========================================================================
    // The full round trip: GRN receipt -> QC pass -> stock_ledger insert
    // -> availability -> posts through ledger-core.
    // =========================================================================

    public function test_grn_receipt_qc_pass_stock_ledger_availability_round_trip(): void
    {
        $user = $this->makeUser('wh@example.com', 'warehouse');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $quarantine = $this->withHeaders($headers)->postJson('/api/stock/quarantine', [
            'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 100, 'unit_cost' => 350,
        ])->assertCreated();

        $this->assertDatabaseHas('stock_quarantines', ['id' => $quarantine->json('id'), 'qc_status' => 'pending']);

        // Nothing in stock_ledger yet - goods aren't available until QC passes (§6).
        $this->assertSame('0.0000', app(\App\Services\StockValuationService::class)->onHandQuantity($this->item, $this->warehouse));

        $this->withHeaders($headers)->postJson('/api/stock/quality-checks', [
            'stock_quarantine_id' => $quarantine->json('id'), 'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $this->assertDatabaseHas('stock_quarantines', ['id' => $quarantine->json('id'), 'qc_status' => 'passed']);
        $this->assertDatabaseHas('stock_ledger', [
            'tenant_id' => $this->tenant->id, 'item_id' => $this->item->id, 'movement_type' => 'receipt', 'quantity' => 100,
        ]);

        $availability = $this->withHeaders($headers)->getJson(
            "/api/stock/availability?item_id={$this->item->id}&warehouse_id={$this->warehouse->id}"
        )->assertOk();

        $this->assertSame('100.0000', $availability->json('on_hand_quantity'));
        $this->assertSame('35000.00', $availability->json('on_hand_value'));
        $this->assertSame('100.0000', $availability->json('available_quantity'));

        // Posted correctly through ledger-core: Dr Inventory (RM), Cr Accounts Payable, balanced.
        $entry = \App\Models\JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'grn_receipt')->with('lines.account')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(3500000, $entry->amountFor('Inventory (RM)')->cents());
        $this->assertSame(3500000, $entry->amountFor('Accounts Payable', 'credit')->cents());
    }

    public function test_failed_qc_never_touches_stock_ledger(): void
    {
        $user = $this->makeUser('wh2@example.com', 'warehouse');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $quarantine = $this->withHeaders($headers)->postJson('/api/stock/quarantine', [
            'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 50, 'unit_cost' => 200,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/stock/quality-checks', [
            'stock_quarantine_id' => $quarantine->json('id'), 'result' => 'fail', 'disposition' => 'scrap',
        ])->assertCreated();

        $this->assertDatabaseHas('stock_quarantines', ['id' => $quarantine->json('id'), 'qc_status' => 'failed']);
        $this->assertDatabaseMissing('stock_ledger', ['tenant_id' => $this->tenant->id, 'item_id' => $this->item->id]);
        $this->assertSame(0, \App\Models\JournalEntry::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_standard_cost_item_receipt_posts_actual_and_variance_correctly(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Std', 'valuation_method' => 'standard_cost']);
        $uom = UnitOfMeasure::where('tenant_id', $this->tenant->id)->first();
        $item = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'STD-001', 'name' => 'Std Item',
            'category_id' => $category->id, 'type' => 'raw_material', 'uom_id' => $uom->id,
            'standard_cost_cents' => Money::fromMajor(350),
        ]);

        $user = $this->makeUser('wh3@example.com', 'warehouse');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $quarantine = $this->withHeaders($headers)->postJson('/api/stock/quarantine', [
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 100, 'unit_cost' => 365, // actual exceeds standard - unfavorable variance
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/stock/quality-checks', [
            'stock_quarantine_id' => $quarantine->json('id'), 'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        // stock_ledger records the STANDARD cost, never the actual GRN cost (§3.4).
        $this->assertDatabaseHas('stock_ledger', ['item_id' => $item->id, 'unit_cost_cents' => 35000]);

        $entry = \App\Models\JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'grn_receipt_standard_cost')->with('lines.account')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(3500000, $entry->amountFor('Inventory (RM)')->cents());
        $this->assertSame(3650000, $entry->amountFor('Accounts Payable', 'credit')->cents());
        $this->assertSame(150000, $entry->amountFor('Purchase Price Variance')->cents());
    }

    // =========================================================================
    // The locked check-and-reserve mechanism (§6) - the exit criterion's
    // own concurrency requirement.
    // =========================================================================

    public function test_hard_reservation_within_available_stock_succeeds(): void
    {
        $this->receiveAndPass($this->item, $this->warehouse, 10, 100);

        $service = app(StockAvailabilityService::class);
        $reservation = $service->reserve($this->item, $this->warehouse, '5', 'hard');

        $this->assertSame('active', $reservation->status);
        $this->assertSame('5.0000', $service->availableQuantity($this->item, $this->warehouse));
    }

    public function test_hard_reservation_exceeding_available_stock_is_rejected(): void
    {
        $this->receiveAndPass($this->item, $this->warehouse, 5, 100);

        $service = app(StockAvailabilityService::class);

        $this->expectException(InsufficientStockException::class);
        $service->reserve($this->item, $this->warehouse, '10', 'hard');
    }

    public function test_soft_reservation_never_blocks_on_availability(): void
    {
        $this->receiveAndPass($this->item, $this->warehouse, 1, 100);

        $service = app(StockAvailabilityService::class);
        // Soft reserve does NOT reduce available-to-promise (§6/§3.2) - a
        // soft reservation for more than on-hand still succeeds.
        $reservation = $service->reserve($this->item, $this->warehouse, '999', 'soft');

        $this->assertSame('active', $reservation->status);
        $this->assertSame('1.0000', $service->availableQuantity($this->item, $this->warehouse));
    }

    // The genuine two-process concurrency test (proving the lock actually
    // serializes two real, separate database connections) lives in
    // InventoryConcurrencyTest, not here - RefreshDatabase wraps this
    // entire test class in one uncommitted transaction, so a spawned
    // subprocess's own connection could never see this class's setup
    // data (only committed rows are visible across connections).

    private function receiveAndPass(Item $item, Warehouse $warehouse, int $quantity, int $unitCostMajor): void
    {
        $user = $this->makeUser('receiver-'.uniqid().'@example.com', 'warehouse');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $quarantine = $this->withHeaders($headers)->postJson('/api/stock/quarantine', [
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity' => $quantity, 'unit_cost' => $unitCostMajor,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/stock/quality-checks', [
            'stock_quarantine_id' => $quarantine->json('id'), 'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_availability_is_tenant_scoped(): void
    {
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);
        $categoryB = Category::create(['tenant_id' => $tenantB->id, 'name' => 'B Cat', 'valuation_method' => 'fifo']);
        $uomB = UnitOfMeasure::create(['tenant_id' => $tenantB->id, 'code' => 'PC', 'name' => 'Piece']);
        $itemB = Item::create(['tenant_id' => $tenantB->id, 'sku' => 'B-001', 'name' => "B's item", 'category_id' => $categoryB->id, 'type' => 'raw_material', 'uom_id' => $uomB->id]);
        $warehouseB = Warehouse::where('tenant_id', $tenantB->id)->where('is_default', true)->first();

        $userA = $this->makeUser('a@example.com', 'admin');
        $tokenA = $this->tokenFor($userA);

        // Tenant A's user cannot query availability against Tenant B's item/warehouse.
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/stock/availability?item_id={$itemB->id}&warehouse_id={$warehouseB->id}")
            ->assertStatus(422);
    }
}
