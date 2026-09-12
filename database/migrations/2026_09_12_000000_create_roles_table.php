<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The initial RBAC role set (architecture doc §11). This is a fixed
     * system-wide catalog, not tenant-scoped — unlike TaxCode/Category
     * (§3.1's tenancy rule), the role set itself isn't something a tenant
     * customizes in v1; CASL (frontend) and Policies (server-side) both key
     * off these same rows. Flagged as a call worth confirming, not a silent
     * assumption: the architecture doc never states this explicitly either
     * way, only that "no exceptions" applies to reference-feeling tables in
     * general.
     */
    private const ROLES = [
        'sales' => 'Sales',
        'procurement' => 'Procurement',
        'warehouse' => 'Warehouse',
        'finance' => 'Finance',
        'project_manager' => 'Project Manager',
        'site_supervisor' => 'Site Supervisor',
        'hr_manager' => 'HR Manager',
        'asset_manager' => 'Asset Manager',
        'admin' => 'Admin',
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });

        $now = now();
        DB::table('roles')->insert(
            collect(self::ROLES)->map(fn ($label, $name) => [
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
