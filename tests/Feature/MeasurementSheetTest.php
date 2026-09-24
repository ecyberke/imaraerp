<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqLine;
use App\Models\Party;
use App\Models\Role;
use App\Models\Subcontract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * phase1-screens: MeasurementSheet was modeled in crm-sales-boq but
 * never got a controller until this branch's "Bill from BOQ" UX needed
 * a real certify action to bill against certified quantities.
 */
class MeasurementSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurement_sheet_can_be_recorded_and_certified(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Client Co', 'type' => 'customer', 'tax_residency_status' => 'resident_certified']);
        $subcontract = Subcontract::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'status' => 'active']);
        $boq = Boq::create(['tenant_id' => $tenant->id, 'boqable_type' => Subcontract::class, 'boqable_id' => $subcontract->id, 'status' => 'draft']);
        $boqLine = BoqLine::create([
            'tenant_id' => $tenant->id, 'boq_id' => $boq->id, 'description' => 'Excavation',
            'quantity' => 100, 'rate_cents' => \App\Support\Money::fromMajor('500'), 'amount_cents' => \App\Support\Money::fromMajor('50000'),
        ]);

        $role = Role::where('tenant_id', $tenant->id)->where('name', 'sales')->first();
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Sales', 'email' => 'sales@example.com',
            'role_id' => $role->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $sheet = $this->withHeaders($headers)->postJson("/api/boq-lines/{$boqLine->id}/measurement-sheets", [
            'period' => '2026-09', 'current_qty' => 60,
        ])->assertCreated();
        $this->assertSame('submitted', $sheet->json('status'));
        $this->assertSame('60.0000', $sheet->json('cumulative_qty'));

        $certified = $this->withHeaders($headers)
            ->postJson("/api/measurement-sheets/{$sheet->json('id')}/certify", ['certified_qty' => 55])
            ->assertOk();
        $this->assertSame('certified', $certified->json('status'));
        $this->assertSame('55.0000', $certified->json('certified_qty'));

        $this->withHeaders($headers)->postJson("/api/measurement-sheets/{$sheet->json('id')}/certify")->assertStatus(422);
    }
}
