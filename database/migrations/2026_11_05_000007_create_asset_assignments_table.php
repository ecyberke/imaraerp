<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12: "asset_id (where asset_type = plant_equipment), project_id,
 * assigned_date, released_date (nullable), internal_daily_rate,
 * meter_reading_start/end (nullable)." status isn't in the doc's bare
 * field list, but every other state-carrying entity in this codebase
 * (ResourceAssignment, Milestone, ...) names status explicitly rather
 * than leaving it to be inferred from released_date being null/non-null -
 * added for the same query-ergonomics reason, derived automatically by
 * AssetAssignmentService rather than caller-supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->date('assigned_date');
            $table->date('released_date')->nullable();
            $table->bigInteger('internal_daily_rate_cents');
            $table->decimal('meter_reading_start', 12, 2)->nullable();
            $table->decimal('meter_reading_end', 12, 2)->nullable();
            $table->string('status')->default('active'); // active, released
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
    }
};
