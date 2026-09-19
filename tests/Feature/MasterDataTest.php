<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Category;
use App\Models\Item;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * master-data exit criterion (execution_plan.md): "master data CRUD
 * works, tenant-scoped, policy-gated." §11 names no owning role for any
 * of Party/Category/UnitOfMeasure/Item/BillOfMaterials - the role
 * matrix these tests assert against (Admin/Sales/Procurement for Party;
 * Admin/Procurement/Warehouse-read for the rest) is a reasonable
 * default, flagged for confirmation in the Policy classes' own
 * docblocks, not silently assumed.
 */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Tenant $tenant, string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    // =========================================================================
    // Party
    // =========================================================================

    public function test_procurement_can_create_and_read_a_party(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'proc@example.com', 'procurement');
        $token = $this->tokenFor($user);

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/parties', [
                'name' => 'Jomo Supplies Ltd', 'type' => 'supplier',
                'credit_limit' => 500000, 'payment_terms' => 'credit',
                'tax_residency_status' => 'resident_certified',
            ]);

        $create->assertCreated()->assertJsonPath('name', 'Jomo Supplies Ltd');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/parties/{$create->json('id')}")
            ->assertOk()
            ->assertJsonPath('credit_limit', '500000.00')
            ->assertJsonMissingPath('credit_limit_cents');
    }

    public function test_warehouse_role_cannot_create_a_party(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'wh@example.com', 'warehouse');
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/parties', ['name' => 'Should Fail', 'type' => 'customer'])
            ->assertForbidden();
    }

    public function test_party_credit_limit_rejects_negative_values(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'sales@example.com', 'sales');
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/parties', ['name' => 'Bad Party', 'type' => 'customer', 'credit_limit' => -100])
            ->assertStatus(422);
    }

    public function test_a_party_cannot_be_read_across_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $partyB = Party::create(['tenant_id' => $tenantB->id, 'name' => "B's client", 'type' => 'customer']);

        $userA = $this->makeUser($tenantA, 'a@example.com', 'admin');
        $tokenA = $this->tokenFor($userA);

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/parties/{$partyB->id}")
            ->assertNotFound();
    }

    public function test_admin_without_mfa_is_blocked_from_creating_a_party(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'admin@example.com', 'admin');
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/parties', ['name' => 'Blocked', 'type' => 'customer'])
            ->assertForbidden()
            ->assertJsonPath('mfa_setup_required', true);
    }

    public function test_admin_can_delete_a_party_but_sales_cannot(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'To Delete', 'type' => 'customer']);

        $salesUser = $this->makeUser($tenant, 'sales2@example.com', 'sales');
        $this->withHeader('Authorization', "Bearer {$this->tokenFor($salesUser)}")
            ->deleteJson("/api/parties/{$party->id}")
            ->assertForbidden();
    }

    // =========================================================================
    // Category / UnitOfMeasure / Item — the shared valuation_method /
    // standard_cost interaction (§3.1)
    // =========================================================================

    public function test_procurement_can_create_category_uom_and_item(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'proc2@example.com', 'procurement');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $category = $this->withHeaders($headers)->postJson('/api/categories', [
            'name' => 'Cement', 'valuation_method' => 'fifo',
        ])->assertCreated();

        $uom = $this->withHeaders($headers)->postJson('/api/units-of-measure', [
            'code' => 'BAG50', 'name' => 'Bag (50kg)',
        ])->assertCreated();

        $item = $this->withHeaders($headers)->postJson('/api/items', [
            'sku' => 'CEM-001', 'name' => 'Portland Cement 50kg',
            'category_id' => $category->json('id'), 'type' => 'raw_material',
            'uom_id' => $uom->json('id'), 'reorder_level' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('items', ['id' => $item->json('id'), 'tenant_id' => $tenant->id]);
    }

    public function test_standard_cost_rejected_on_a_non_standard_cost_category(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'proc3@example.com', 'procurement');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'FIFO Category', 'valuation_method' => 'fifo']);
        $uom = UnitOfMeasure::create(['tenant_id' => $tenant->id, 'code' => 'PC', 'name' => 'Piece']);

        $this->withHeaders($headers)->postJson('/api/items', [
            'sku' => 'BAD-001', 'name' => 'Bad Item', 'category_id' => $category->id,
            'type' => 'raw_material', 'uom_id' => $uom->id, 'standard_cost' => 100,
        ])->assertStatus(422);
    }

    public function test_standard_cost_accepted_on_a_standard_cost_category(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'proc4@example.com', 'procurement');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Std Cost Category', 'valuation_method' => 'standard_cost']);
        $uom = UnitOfMeasure::create(['tenant_id' => $tenant->id, 'code' => 'PC', 'name' => 'Piece']);

        $response = $this->withHeaders($headers)->postJson('/api/items', [
            'sku' => 'GOOD-001', 'name' => 'Good Item', 'category_id' => $category->id,
            'type' => 'raw_material', 'uom_id' => $uom->id, 'standard_cost' => 350,
        ])->assertCreated();

        $this->assertSame('350.00', $response->json('standard_cost'));
        $this->assertArrayNotHasKey('standard_cost_cents', $response->json());
    }

    public function test_item_category_id_cannot_reference_another_tenants_category(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'plan_tier' => 'starter']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $categoryB = Category::create(['tenant_id' => $tenantB->id, 'name' => "B's category", 'valuation_method' => 'fifo']);
        $uomA = UnitOfMeasure::create(['tenant_id' => $tenantA->id, 'code' => 'PC', 'name' => 'Piece']);

        $userA = $this->makeUser($tenantA, 'a2@example.com', 'procurement');
        $token = $this->tokenFor($userA);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/items', [
                'sku' => 'CROSS-001', 'name' => 'Cross Tenant Item',
                'category_id' => $categoryB->id, 'type' => 'raw_material', 'uom_id' => $uomA->id,
            ])->assertStatus(422);
    }

    // =========================================================================
    // BillOfMaterials
    // =========================================================================

    public function test_bom_can_be_created_with_lines_and_quantity_is_validated(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $user = $this->makeUser($tenant, 'proc5@example.com', 'procurement');
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General', 'valuation_method' => 'fifo']);
        $uom = UnitOfMeasure::create(['tenant_id' => $tenant->id, 'code' => 'PC', 'name' => 'Piece']);

        $fg = Item::create(['tenant_id' => $tenant->id, 'sku' => 'FG-001', 'name' => 'Roof Sheet', 'category_id' => $category->id, 'type' => 'finished_good', 'uom_id' => $uom->id]);
        $rm = Item::create(['tenant_id' => $tenant->id, 'sku' => 'RM-001', 'name' => 'Steel Coil', 'category_id' => $category->id, 'type' => 'raw_material', 'uom_id' => $uom->id]);

        $response = $this->withHeaders($headers)->postJson('/api/bill-of-materials', [
            'finished_good_item_id' => $fg->id,
            'wastage_allowance_pct' => 0.05,
            'source' => 'manual',
            'lines' => [
                ['raw_material_item_id' => $rm->id, 'quantity' => 2.5],
            ],
        ])->assertCreated();

        $this->assertCount(1, $response->json('lines'));

        // Zero quantity on a BOM line is rejected outright (§1.1) - no
        // variation_type = omission carve-out applies to a BOM line.
        $this->withHeaders($headers)->postJson('/api/bill-of-materials', [
            'finished_good_item_id' => $fg->id, 'source' => 'manual',
            'lines' => [['raw_material_item_id' => $rm->id, 'quantity' => 0]],
        ])->assertStatus(422);
    }

    public function test_bom_tolerance_pct_defaults_to_wastage_allowance_when_not_set(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General', 'valuation_method' => 'fifo']);
        $uom = UnitOfMeasure::create(['tenant_id' => $tenant->id, 'code' => 'PC', 'name' => 'Piece']);
        $fg = Item::create(['tenant_id' => $tenant->id, 'sku' => 'FG-002', 'name' => 'Panel', 'category_id' => $category->id, 'type' => 'finished_good', 'uom_id' => $uom->id]);

        $bom = BillOfMaterial::create([
            'tenant_id' => $tenant->id, 'finished_good_item_id' => $fg->id,
            'wastage_allowance_pct' => 0.08, 'tolerance_pct' => null, 'source' => 'manual',
        ]);

        $this->assertSame('0.0800', $bom->effectiveTolerancePct());

        $bom->update(['tolerance_pct' => 0.02]);
        $this->assertSame('0.0200', $bom->effectiveTolerancePct());
    }

    // =========================================================================
    // User <-> Employee stub (§3.1) - Employee itself doesn't exist until
    // hr-payroll (Phase 2); this just proves the nullable column/fillable
    // exists now, per the doc's explicit "User, extended: employee_id".
    // =========================================================================

    public function test_user_employee_id_is_nullable_and_settable(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = Role::where('tenant_id', $tenant->id)->where('name', 'admin')->first();

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'No Employee Yet', 'email' => 'noemp@example.com',
            'role_id' => $adminRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $this->assertNull($user->employee_id);

        $user->update(['employee_id' => 999]);
        $this->assertSame(999, $user->fresh()->employee_id);
    }
}
