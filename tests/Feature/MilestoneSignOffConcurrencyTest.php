<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §3.7's named concurrency requirement: "two simultaneous sign-offs
 * prevented via a transaction holding a row lock on the Milestone." Same
 * reasoning as InventoryConcurrencyTest/CreditApprovalConcurrencyTest for
 * why this is its own class ($connectionsToTransact = [] disables
 * RefreshDatabase's transaction wrapping, since a genuinely separate
 * subprocess needs to see this test's setup data as committed).
 */
class MilestoneSignOffConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            $tenantId = $this->tenant->id;
            DB::table('milestones')->where('tenant_id', $tenantId)->delete();
            DB::table('projects')->where('tenant_id', $tenantId)->delete();
            DB::table('parties')->where('tenant_id', $tenantId)->delete();
            DB::table('approval_limits')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            DB::table('tenants')->where('id', $tenantId)->delete();
        }

        parent::tearDown();
    }

    /**
     * Two real OS processes racing to SELECT ... FOR UPDATE the same
     * Milestone row and flip utilized -> signed_off - the actual mechanism
     * MilestoneService::signOff() uses, reimplemented directly against
     * Postgres so this proves the database-level lock, not just that two
     * sequential application calls happen to work.
     */
    public function test_two_concurrent_sign_offs_on_the_same_milestone_only_one_succeeds(): void
    {
        $this->tenant = Tenant::create(['name' => 'Concurrency Projects Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Race Condition Client', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'name' => 'Race Site', 'status' => 'in_progress']);
        $milestone = Milestone::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'sequence' => 1,
            'description' => 'Foundations', 'status' => 'utilized',
        ]);

        $script = $this->writeConcurrentSignOffScript();
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
                ['php', $script, (string) $milestone->id],
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

        $signed = array_filter($results, fn ($r) => $r === 'SIGNED');
        $rejected = array_filter($results, fn ($r) => $r === 'REJECTED');

        self::assertCount(1, $signed, 'exactly one of the two concurrent sign-offs must succeed: got '.json_encode($results));
        self::assertCount(1, $rejected, 'exactly one of the two concurrent sign-offs must be rejected: got '.json_encode($results));
        self::assertSame('signed_off', $milestone->fresh()->status);
    }

    /**
     * Reimplements MilestoneService::signOff()'s lock/read/decide sequence
     * directly against Postgres via PDO, standalone (no Laravel bootstrap).
     */
    private function writeConcurrentSignOffScript(): string
    {
        $path = storage_path('framework/testing/concurrent_signoff_'.uniqid().'.php');

        $code = <<<'PHP'
<?php
[$script, $milestoneId] = $argv;

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CR_HOST'), getenv('CR_PORT'), getenv('CR_DB')),
    getenv('CR_USER'),
    getenv('CR_PASS'),
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->beginTransaction();

$locked = $pdo->prepare('SELECT status FROM milestones WHERE id = ? FOR UPDATE');
$locked->execute([$milestoneId]);
$status = $locked->fetch()['status'];

// Hold the lock briefly so the sibling process (started immediately
// after this one, before either commits) genuinely has to wait on
// Postgres rather than getting lucky with OS scheduling timing.
usleep(300000);

if ($status === 'utilized') {
    $update = $pdo->prepare("UPDATE milestones SET status = 'signed_off' WHERE id = ?");
    $update->execute([$milestoneId]);
    $result = 'SIGNED';
} else {
    $result = 'REJECTED';
}

$pdo->commit();
echo $result;
PHP;

        file_put_contents($path, $code);

        return $path;
    }
}
