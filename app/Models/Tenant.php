<?php

namespace App\Models;

use App\Services\ChartOfAccountsSeeder;
use App\Services\TaxCodeSeeder;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'plan_tier',
    ];

    protected static function booted(): void
    {
        // Role is tenant-scoped (§11/v20): every tenant gets its own copy of
        // the fixed 9-role catalog, seeded the moment the Tenant row exists —
        // UserInvitation.role_id needs a real, tenant-owned Role to reference
        // before an admin is ever invited, which happens before onboarding
        // (Chart of Accounts seed, DocumentSequence init) runs.
        static::created(function (Tenant $tenant) {
            $tenant->seedDefaultRoles();
            $tenant->seedInitialAccountingPeriods();
            ChartOfAccountsSeeder::seed($tenant);
            TaxCodeSeeder::seed($tenant);
            $tenant->seedDefaultWarehouses();
            $tenant->seedDefaultCurrency();
        });
    }

    /**
     * KES is always the base currency (every ledger posting and
     * stock_ledger.unit_cost is base-currency-only, §3.4/§7) - seeded so
     * PurchaseOrder always has a real currency_id to default to, even for
     * a tenant that never touches foreign-currency purchasing.
     */
    public function seedDefaultCurrency(): void
    {
        DB::table('currencies')->insert([
            'tenant_id' => $this->getKey(), 'code' => 'KES', 'name' => 'Kenyan Shilling',
            'is_base' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Architecture §12 item #4 (resolved): multi-warehouse required from
     * day one, "Main Warehouse" seeded as the v1 default. §3.3 also names
     * a designated "In Transit" warehouse transfer_out/transfer_in
     * movements land goods in/out of - seeded here too so it exists
     * before the first transfer is ever attempted.
     */
    public function seedDefaultWarehouses(): void
    {
        $now = now();

        DB::table('warehouses')->insert([
            [
                'tenant_id' => $this->getKey(), 'name' => 'Main Warehouse', 'code' => 'MAIN',
                'is_default' => true, 'is_in_transit' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'tenant_id' => $this->getKey(), 'name' => 'In Transit', 'code' => 'TRANSIT',
                'is_default' => false, 'is_in_transit' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function seedDefaultRoles(): void
    {
        $now = now();

        $rows = collect(Role::CATALOG)->map(fn ($label, $name) => [
            'tenant_id' => $this->getKey(),
            'name' => $name,
            'label' => $label,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('roles')->insertOrIgnore($rows);
    }

    /**
     * Architecture §3.10 defines AccountingPeriod's shape and the
     * backdating rule but not who creates the rows or at what grain -
     * that's an onboarding detail, not specified either way. Twelve
     * monthly, open-by-default periods for the current calendar year is
     * the standard real-world default and gives a brand-new tenant
     * somewhere to post from day one; worth confirming rather than
     * assuming if a different grain (e.g. matching a client's actual
     * fiscal year, which can differ from the calendar year) is wanted.
     */
    public function seedInitialAccountingPeriods(): void
    {
        $year = BusinessTime::today()->year;
        $now = now();

        $rows = [];
        for ($month = 1; $month <= 12; $month++) {
            $start = \Carbon\Carbon::create($year, $month, 1);
            $rows[] = [
                'tenant_id' => $this->getKey(),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('accounting_periods')->insert($rows);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }
}
