<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * resources.employee_id (labour-resourcing) was a forward reference to
 * Employee, which didn't exist yet - backfilled with its real FK now that
 * it does, same pattern as projects-milestones-ui's own backfill
 * migration. §3.11 also lists an `Employee.resource_id` pointing the
 * other way, which would make the two tables redundantly bidirectional
 * (either side alone is enough to join them) - resolved here the same
 * way this document's own prior reviews resolved similar redundancies
 * (v7's Subcontract.retention_terms_id removal): Employee does NOT get
 * its own resource_id column, it gets a `resource()` accessor
 * (hasOne against this same FK) instead. One real column, two directions
 * of lookup, no duplicate-pointer sync problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });
    }
};
