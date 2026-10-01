<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqLine;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\VariationOrder;
use App\Models\VariationOrderLine;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §3.7's named concurrency requirement: "row lock on affected BoqLine/
 * BoqSection rows during approval; a second VO touching an already-locked
 * line must queue or fail with an explicit retry message." This proves
 * the "queue" half against a real Postgres lock: two different
 * VariationOrders, each with a line against the SAME BoqLine, approved by
 * two genuinely concurrent OS processes. Neither delta may be lost - the
 * final amount must reflect both.
 */
class VariationOrderApprovalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            $tenantId = $this->tenant->id;
            DB::table('variation_order_lines')->where('tenant_id', $tenantId)->delete();
            DB::table('variation_orders')->where('tenant_id', $tenantId)->delete();
            DB::table('boq_lines')->where('tenant_id', $tenantId)->delete();
            DB::table('boqs')->where('tenant_id', $tenantId)->delete();
            DB::table('projects')->where('tenant_id', $tenantId)->delete();
            DB::table('parties')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            DB::table('tenants')->where('id', $tenantId)->delete();
        }

        parent::tearDown();
    }

    public function test_two_concurrent_variation_order_approvals_against_the_same_boq_line_both_apply(): void
    {
        $this->tenant = Tenant::create(['name' => 'Concurrency Projects Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Race Condition Client', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'name' => 'Race Site', 'status' => 'in_progress']);
        $boq = Boq::create(['tenant_id' => $this->tenant->id, 'boqable_type' => Project::class, 'boqable_id' => $project->id, 'status' => 'approved']);
        $boqLine = BoqLine::create([
            'tenant_id' => $this->tenant->id, 'boq_id' => $boq->id, 'description' => 'Excavation',
            'unit' => 'm3', 'quantity' => 100, 'rate_cents' => Money::fromMajor('1000'),
            'amount_cents' => Money::fromMajor('100000'), 'line_type' => 'measured',
        ]);

        $vos = [];
        foreach ([0, 1] as $i) {
            $vos[$i] = VariationOrder::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'variation_number' => "VO-{$project->id}-".($i + 1),
                'description' => "Extra excavation {$i}", 'status' => 'submitted', 'submitted_date' => now(),
            ]);
            VariationOrderLine::create([
                'tenant_id' => $this->tenant->id, 'variation_order_id' => $vos[$i]->id, 'boq_line_id' => $boqLine->id,
                'variation_type' => 'quantity_change', 'quantity_delta' => 5,
                'amount_delta_cents' => Money::fromMajor('5000'), 'description' => "Extra 5m3 #{$i}",
            ]);
        }

        $script = $this->writeConcurrentApprovalScript();
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
                ['php', $script, (string) $boqLine->id, (string) $vos[$i]->id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
                $env,
            );
        }

        foreach ([0, 1] as $i) {
            $result = trim(stream_get_contents($pipes[$i][1]));
            $stderr = trim(stream_get_contents($pipes[$i][2]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($processes[$i]);
            self::assertSame('', $stderr, "subprocess {$i} wrote to stderr: {$stderr}");
            self::assertSame('APPROVED', $result);
        }

        @unlink($script);

        // Neither VO's delta was lost: 100,000 + 5,000 + 5,000 = 110,000.
        self::assertSame('110000.00', $boqLine->fresh()->amount->toMajor());
        self::assertSame('approved', $vos[0]->fresh()->status);
        self::assertSame('approved', $vos[1]->fresh()->status);
    }

    /**
     * Reimplements VariationOrderService::approve()'s lock/read/mutate
     * sequence directly against Postgres via PDO, standalone (no Laravel
     * bootstrap).
     */
    private function writeConcurrentApprovalScript(): string
    {
        $path = storage_path('framework/testing/concurrent_vo_approval_'.uniqid().'.php');

        $code = <<<'PHP'
<?php
[$script, $boqLineId, $variationOrderId] = $argv;

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CR_HOST'), getenv('CR_PORT'), getenv('CR_DB')),
    getenv('CR_USER'),
    getenv('CR_PASS'),
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->beginTransaction();

$locked = $pdo->prepare('SELECT amount_cents FROM boq_lines WHERE id = ? FOR UPDATE');
$locked->execute([$boqLineId]);
$amountCents = (int) $locked->fetch()['amount_cents'];

$lineStmt = $pdo->prepare('SELECT amount_delta_cents FROM variation_order_lines WHERE variation_order_id = ?');
$lineStmt->execute([$variationOrderId]);
$deltaCents = (int) $lineStmt->fetch()['amount_delta_cents'];

// Hold the lock briefly so the sibling process (started immediately
// after this one, before either commits) genuinely has to wait on
// Postgres rather than getting lucky with OS scheduling timing.
usleep(300000);

$update = $pdo->prepare('UPDATE boq_lines SET amount_cents = ? WHERE id = ?');
$update->execute([$amountCents + $deltaCents, $boqLineId]);

$approve = $pdo->prepare("UPDATE variation_orders SET status = 'approved', approved_date = now() WHERE id = ?");
$approve->execute([$variationOrderId]);

$pdo->commit();
echo 'APPROVED';
PHP;

        file_put_contents($path, $code);

        return $path;
    }
}
