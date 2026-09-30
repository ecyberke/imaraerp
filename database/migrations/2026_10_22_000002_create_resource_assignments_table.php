<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.6: "resource_id, project_id or sales_order_id, block_start_date,
 * block_end_date, status (scheduled, active, completed, cancelled)."
 * project_id is a forward reference to Project (projects-milestones-ui,
 * not yet built) - nullable, no FK, same pattern as everywhere else;
 * sales_order_id is real today, since SalesOrder already exists
 * (crm-sales-boq). Both nullable - exactly one is expected to be set
 * per assignment, enforced in ResourceAssignmentService rather than a
 * DB constraint (a CHECK constraint expressing "exactly one of two
 * nullable columns" is portable but awkward in a migration; the
 * service is the single write path anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained();
            $table->date('block_start_date');
            $table->date('block_end_date');
            $table->string('status')->default('scheduled'); // scheduled, active, completed, cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'block_start_date']);
            $table->index(['tenant_id', 'status', 'block_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_assignments');
    }
};
