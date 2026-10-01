<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1. tolerance_pct is nullable and defaults to
 * wastage_allowance_pct when not set - implemented as a read-time
 * fallback on the model (BillOfMaterial::effectiveTolerancePct()), not
 * copied into the column at write time, so a later change to
 * wastage_allowance_pct is still reflected for any BOM that never
 * overrode tolerance_pct.
 *
 * project_id is nullable with no FK constraint yet - Project doesn't
 * exist until projects-milestones-ui (Phase 2). Same pattern as
 * journal_entries.reference_id and users.employee_id: the column exists
 * now per the architecture doc, the FK constraint gets added once the
 * table it points to actually exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_of_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finished_good_item_id')->constrained('items');
            $table->decimal('wastage_allowance_pct', 6, 4)->default(0);
            $table->decimal('tolerance_pct', 6, 4)->nullable();
            $table->string('source'); // uploaded, generated, manual
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'finished_good_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_of_materials');
    }
};
