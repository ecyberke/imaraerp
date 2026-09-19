<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockReservation;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * inventory-core exit criterion (execution_plan.md): "two simulated
 * concurrent reservations against the last unit of an item resolve
 * correctly (one succeeds, one gets a clear retry, never both)."
 *
 * Split into its own test class deliberately: RefreshDatabase wraps a
 * test method in one uncommitted transaction and rolls it back at
 * teardown, for speed and isolation. That's incompatible with this test's
 * actual requirement - a *second, genuinely separate* database connection
 * (a real subprocess here, not just a second PDO handle in the same PHP
 * process, since PHP has no real threading and would just run both
 * sequentially anyway) needs to see the stock this test received as
 * *committed* data, which an uncommitted wrapping transaction can never
 * provide. $connectionsToTransact = [] disables that wrapping for this
 * class only, so every write here genuinely commits - and correspondingly
 * this class cleans up its own rows in tearDown() rather than relying on
 * rollback.
 */
class InventoryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            $tenantId = $this->tenant->id;
            DB::table('stock_reservations')->where('tenant_id', $tenantId)->delete();
            DB::table('stock_ledger')->where('tenant_id', $tenantId)->delete();
            DB::table('stock_quarantines')->where('tenant_id', $tenantId)->delete();
            DB::table('journal_lines')->where('tenant_id', $tenantId)->delete();
            DB::table('journal_entries')->where('tenant_id', $tenantId)->delete();
            DB::table('personal_access_tokens')->whereIn(
                'tokenable_id', DB::table('users')->where('tenant_id', $tenantId)->pluck('id')
            )->delete();
            DB::table('users')->where('tenant_id', $tenantId)->delete();
            DB::table('items')->where('tenant_id', $tenantId)->delete();
            DB::table('categories')->where('tenant_id', $tenantId)->delete();
            DB::table('units_of_measure')->where('tenant_id', $tenantId)->delete();
            DB::table('warehouses')->where('tenant_id', $tenantId)->delete();
            DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->delete();
            DB::table('tax_codes')->where('tenant_id', $tenantId)->delete();
            DB::table('accounting_periods')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            DB::table('tenants')->where('id', $tenantId)->delete();
        }

        parent::tearDown();
    }

    /**
     * Genuinely concurrent via two real OS processes racing to lock the
     * same stock_ledger rows via SELECT ... FOR UPDATE - the actual
     * mechanism StockAvailabilityService::reserve() uses, reimplemented
     * directly against Postgres here (not through the HTTP/service layer)
     * specifically so this test proves the *database-level* lock, not
     * just that two sequential application calls happen to work.
     */
    public function test_two_concurrent_hard_reservations_against_the_last_unit_resolve_to_exactly_one_success(): void
    {
        $this->tenant = Tenant::create(['name' => 'Concurrency Test Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'General', 'valuation_method' => 'fifo']);
        $uom = UnitOfMeasure::create(['tenant_id' => $this->tenant->id, 'code' => 'PC', 'name' => 'Piece']);
        $item = Item::create([
            'tenant_id' => $this->tenant->id, 'sku' => 'LASTUNIT-001', 'name' => 'Last Unit Item',
            'category_id' => $category->id, 'type' => 'raw_material', 'uom_id' => $uom->id,
        ]);
        $warehouse = Warehouse::where('tenant_id', $this->tenant->id)->where('is_default', true)->first();

        $warehouseRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'warehouse')->first();
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Receiver', 'email' => 'receiver@example.com',
            'role_id' => $warehouseRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        // Receive exactly ONE unit into stock and pass QC - this is the
        // "last unit" both processes will race over. This commits for
        // real (no transaction wrapping in this class), so it will
        // genuinely be visible to the two subprocesses below.
        $headers = ['Authorization' => "Bearer {$token}"];
        $quarantine = $this->withHeaders($headers)->postJson('/api/stock/quarantine', [
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'unit_cost' => 100,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/stock/quality-checks', [
            'stock_quarantine_id' => $quarantine->json('id'), 'result' => 'pass', 'disposition' => 'accept',
        ])->assertCreated();

        $script = $this->writeConcurrentReservationScript();
        $connection = config('database.connections.pgsql');

        // proc_open's $env parameter, when given an array, REPLACES the
        // child's entire environment rather than extending it - passing
        // just the CR_* vars would drop PATH and likely leave the child
        // unable to resolve the bare `php` command at all. Merging with
        // the current process's own environment keeps PATH (and
        // everything else) intact while still injecting the connection
        // details the script needs.
        $env = array_merge(getenv(), [
            'CR_HOST' => (string) $connection['host'],
            'CR_PORT' => (string) $connection['port'],
            'CR_DB' => (string) $connection['database'],
            'CR_USER' => (string) $connection['username'],
            'CR_PASS' => (string) $connection['password'],
        ]);

        $processes = [];
        $pipes = [];
        foreach ([0, 1] as $i) {
            $processes[$i] = proc_open(
                [
                    'php', $script,
                    (string) $this->tenant->id, (string) $item->id, (string) $warehouse->id,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
                $env,
            );
        }

        $results = [];
        foreach ([0, 1] as $i) {
            $results[$i] = trim(stream_get_contents($pipes[$i][1]));
            $stderr = trim(stream_get_contents($pipes[$i][2]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($processes[$i]);
            self::assertSame('', $stderr, "subprocess {$i} wrote to stderr: {$stderr}");
        }

        @unlink($script);

        $successes = array_filter($results, fn ($r) => $r === 'SUCCESS');
        $failures = array_filter($results, fn ($r) => $r === 'FAILED');

        self::assertCount(1, $successes, 'exactly one of the two concurrent reservations must succeed: got '.json_encode($results));
        self::assertCount(1, $failures, 'exactly one of the two concurrent reservations must fail cleanly (a clear retry signal, never both succeeding): got '.json_encode($results));

        self::assertSame(1, StockReservation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('item_id', $item->id)->where('status', 'active')->count());
    }

    /**
     * Reimplements exactly the lock/read/write sequence
     * StockAvailabilityService::reserve() performs, directly against
     * Postgres via PDO, so it can run as a standalone subprocess with no
     * Laravel bootstrap (fast, and genuinely isolated from the test
     * process's own connection).
     */
    private function writeConcurrentReservationScript(): string
    {
        $path = storage_path('framework/testing/concurrent_reserve_'.uniqid().'.php');

        $code = <<<'PHP'
<?php
[$script, $tenantId, $itemId, $warehouseId] = $argv;

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CR_HOST'), getenv('CR_PORT'), getenv('CR_DB')),
    getenv('CR_USER'),
    getenv('CR_PASS'),
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->beginTransaction();

// Same lock StockAvailabilityService::lockForReservation() takes: every
// stock_ledger row for this item/warehouse, deterministically ordered.
$locked = $pdo->prepare('SELECT id FROM stock_ledger WHERE tenant_id = ? AND item_id = ? AND warehouse_id = ? ORDER BY id FOR UPDATE');
$locked->execute([$tenantId, $itemId, $warehouseId]);
$lockedRows = $locked->fetchAll();

if (empty($lockedRows)) {
    $key = crc32("stock-reserve:{$tenantId}:{$itemId}:{$warehouseId}");
    $pdo->exec("SELECT pg_advisory_xact_lock({$key})");
}

$onHandStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN movement_type IN ('receipt','transfer_in','production_output','return') THEN quantity WHEN movement_type = 'adjustment' THEN quantity ELSE -quantity END), 0) AS on_hand FROM stock_ledger WHERE tenant_id = ? AND item_id = ? AND warehouse_id = ?"
);
$onHandStmt->execute([$tenantId, $itemId, $warehouseId]);
$onHandQty = (float) $onHandStmt->fetch()['on_hand'];

$reservedStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(quantity), 0) AS reserved FROM stock_reservations WHERE tenant_id = ? AND item_id = ? AND warehouse_id = ? AND reserve_type = 'hard' AND status = 'active'"
);
$reservedStmt->execute([$tenantId, $itemId, $warehouseId]);
$reservedQty = (float) $reservedStmt->fetch()['reserved'];

$available = $onHandQty - $reservedQty;

// Hold the lock briefly so the sibling process (started immediately
// after this one, before either commits) genuinely has to wait on
// Postgres rather than getting lucky with OS scheduling timing.
usleep(300000);

if ($available >= 1.0) {
    $insert = $pdo->prepare("INSERT INTO stock_reservations (tenant_id, item_id, warehouse_id, reserve_type, status, quantity, created_at, updated_at) VALUES (?, ?, ?, 'hard', 'active', 1, now(), now())");
    $insert->execute([$tenantId, $itemId, $warehouseId]);
    $pdo->commit();
    echo 'SUCCESS';
} else {
    $pdo->rollBack();
    echo 'FAILED';
}
PHP;

        file_put_contents($path, $code);

        return $path;
    }
}
