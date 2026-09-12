<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Architecture §3.10: row-locked counter, gap-free, per-tenant,
 * {prefix}-{fiscal_year}-{next_number zero-padded to 5 digits}. Not yet
 * wired to a controller (no document-issuing entity exists until
 * procurement/crm-sales-boq/finance-billing) - exercised directly here as
 * the infrastructure platform-foundation is responsible for.
 */
class DocumentSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_numbers_are_sequential_and_zero_padded(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $service = new DocumentSequenceService;
        $year = now()->year;

        $numbers = DB::transaction(fn () => [
            $service->next($tenant, 'invoice'),
            $service->next($tenant, 'invoice'),
            $service->next($tenant, 'invoice'),
        ]);

        $this->assertSame([
            "INV-{$year}-00001",
            "INV-{$year}-00002",
            "INV-{$year}-00003",
        ], $numbers);
    }

    public function test_each_entity_type_has_its_own_independent_counter(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $service = new DocumentSequenceService;
        $year = now()->year;

        DB::transaction(fn () => $service->next($tenant, 'invoice'));
        $firstPo = DB::transaction(fn () => $service->next($tenant, 'purchase_order'));

        $this->assertSame("PO-{$year}-00001", $firstPo);
    }

    public function test_each_tenant_has_its_own_independent_counter(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);
        $service = new DocumentSequenceService;
        $year = now()->year;

        DB::transaction(fn () => $service->next($tenantA, 'invoice'));
        DB::transaction(fn () => $service->next($tenantA, 'invoice'));
        $firstForB = DB::transaction(fn () => $service->next($tenantB, 'invoice'));

        $this->assertSame("INV-{$year}-00001", $firstForB);
    }

    public function test_unknown_entity_type_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $service = new DocumentSequenceService;

        $this->expectException(\InvalidArgumentException::class);

        DB::transaction(fn () => $service->next($tenant, 'not_a_real_type'));
    }
}
