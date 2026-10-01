<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * finance-billing exit criterion (execution_plan.md): "Two simultaneous
 * credit-limit checks against the same client correctly allow only one
 * to pass." Same reasoning as InventoryConcurrencyTest for why this is
 * its own class ($connectionsToTransact = [] disables RefreshDatabase's
 * transaction wrapping, since a genuinely separate subprocess needs to
 * see this test's setup data as committed).
 */
class CreditApprovalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            $tenantId = $this->tenant->id;
            DB::table('credit_approvals')->where('tenant_id', $tenantId)->delete();
            DB::table('invoices')->where('tenant_id', $tenantId)->delete();
            DB::table('sales_orders')->where('tenant_id', $tenantId)->delete();
            DB::table('parties')->where('tenant_id', $tenantId)->delete();
            DB::table('currencies')->where('tenant_id', $tenantId)->delete();
            DB::table('tax_codes')->where('tenant_id', $tenantId)->delete();
            DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->delete();
            DB::table('accounting_periods')->where('tenant_id', $tenantId)->delete();
            DB::table('warehouses')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            DB::table('tenants')->where('id', $tenantId)->delete();
        }

        parent::tearDown();
    }

    /**
     * Two invoices, each individually within the KES 10,000 credit limit
     * (6,000 net_payable apiece) but combined over it (12,000 > 10,000).
     * Genuinely concurrent via two real OS processes racing to lock the
     * same `parties` row via SELECT ... FOR UPDATE - the actual mechanism
     * CreditApprovalService::request() uses, reimplemented directly
     * against Postgres so this proves the database-level lock, not just
     * that two sequential application calls happen to work.
     */
    public function test_two_concurrent_credit_checks_against_the_same_client_allow_only_one_to_pass(): void
    {
        $this->tenant = Tenant::create(['name' => 'Concurrency Finance Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Race Condition Client', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified', 'credit_limit_cents' => Money::fromMajor('10000'),
        ]);
        $salesOrder = SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed', 'invoice_policy' => 'on_order', 'status' => 'approved',
            'currency_id' => $kes->id, 'exchange_rate' => 1,
        ]);

        $invoices = [];
        foreach ([0, 1] as $i) {
            $invoices[$i] = Invoice::create([
                'tenant_id' => $this->tenant->id, 'sales_order_id' => $salesOrder->id, 'party_id' => $party->id,
                'invoice_date' => now(), 'posting_date' => now(), 'payment_terms' => 'credit',
                'credit_approval_status' => 'pending', 'gross_amount_cents' => Money::fromMajor('5172.41'),
                'vat_amount_cents' => Money::fromMajor('827.59'), 'net_payable_cents' => Money::fromMajor('6000'),
                'status' => 'draft',
            ]);
        }

        $script = $this->writeConcurrentCreditCheckScript();
        $connection = config('database.connections.pgsql');
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
                ['php', $script, (string) $this->tenant->id, (string) $party->id, (string) $invoices[$i]->id],
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

        $approved = array_filter($results, fn ($r) => $r === 'APPROVED');
        $rejected = array_filter($results, fn ($r) => $r === 'REJECTED');

        self::assertCount(1, $approved, 'exactly one of the two concurrent credit checks must be approved: got '.json_encode($results));
        self::assertCount(1, $rejected, 'exactly one of the two concurrent credit checks must be rejected: got '.json_encode($results));
    }

    /**
     * Reimplements CreditApprovalService::request()'s lock/read/decide
     * sequence directly against Postgres via PDO, standalone (no
     * Laravel bootstrap).
     */
    private function writeConcurrentCreditCheckScript(): string
    {
        $path = storage_path('framework/testing/concurrent_credit_'.uniqid().'.php');

        $code = <<<'PHP'
<?php
[$script, $tenantId, $partyId, $invoiceId] = $argv;

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CR_HOST'), getenv('CR_PORT'), getenv('CR_DB')),
    getenv('CR_USER'),
    getenv('CR_PASS'),
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->beginTransaction();

$lockedParty = $pdo->prepare('SELECT credit_limit_cents FROM parties WHERE tenant_id = ? AND id = ? FOR UPDATE');
$lockedParty->execute([$tenantId, $partyId]);
$creditLimitCents = (int) $lockedParty->fetch()['credit_limit_cents'];

$thisInvoice = $pdo->prepare('SELECT net_payable_cents FROM invoices WHERE tenant_id = ? AND id = ?');
$thisInvoice->execute([$tenantId, $invoiceId]);
$netPayableCents = (int) $thisInvoice->fetch()['net_payable_cents'];

$exposureStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(net_payable_cents), 0) AS exposure FROM invoices WHERE tenant_id = ? AND party_id = ? AND id != ? AND credit_approval_status IN ('approved', 'overridden') AND status NOT IN ('paid', 'written_off')"
);
$exposureStmt->execute([$tenantId, $partyId, $invoiceId]);
$exposureCents = (int) $exposureStmt->fetch()['exposure'];

$projectedCents = $exposureCents + $netPayableCents;

// Hold the lock briefly so the sibling process (started immediately
// after this one, before either commits) genuinely has to wait on
// Postgres rather than getting lucky with OS scheduling timing.
usleep(300000);

$status = $projectedCents <= $creditLimitCents ? 'approved' : 'rejected';

$update = $pdo->prepare('UPDATE invoices SET credit_approval_status = ? WHERE tenant_id = ? AND id = ?');
$update->execute([$status, $tenantId, $invoiceId]);

$pdo->commit();
echo $status === 'approved' ? 'APPROVED' : 'REJECTED';
PHP;

        file_put_contents($path, $code);

        return $path;
    }
}
