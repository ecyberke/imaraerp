<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12: asset_type distinguishes a static fixed_asset from
 * project-assignable plant_equipment - both depreciate identically, only
 * the latter gets AssetAssignment rows. purchase_cost/residual_value are
 * the CURRENT depreciable basis, not frozen-at-acquisition historicals -
 * AssetRevaluationService updates them going forward so future
 * depreciation runs use the revalued figures, while already-posted
 * AssetDepreciationEntry rows (and AssetRevaluation's own stored
 * net_book_value_at_revaluation) stay immutable historical facts.
 *
 * location_id is a plain, unconstrained nullable column - no `Location`
 * model exists anywhere in this codebase (full WMS/location modeling is
 * explicit backlog, §14; stock_ledger.location_id, §3.3, is the same
 * precedent). lifespan_change_reason is logged via AuditLogObserver's
 * generic old/new-value diff once attached to this model, not a separate
 * mechanism - §3.12's "logged via AuditLog when it changes" is satisfied
 * by the attribute itself being part of the observed model.
 *
 * schedule_reset_at isn't in the doc's bare field list - needed by
 * DepreciationRunService's sum-of-digits method, which depends on "how
 * many months into the schedule is this" to pick the right year's digit.
 * §10.1's Depreciation Schedule report says the Planned view is
 * "recalculated forward whenever a revaluation or lifespan change
 * occurs" - this is that recalculation's anchor date, bumped by both
 * AssetRevaluationService::revalue() and AssetService::updateLifespan()
 * so the digit-count restarts from the change rather than from
 * acquisition. straight_line and diminishing_balance don't need it
 * (both recompute purely from the asset's current fields every run), so
 * this only affects sum_of_digits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('asset_number');
            $table->string('name');
            $table->foreignId('category_id')->constrained('asset_categories');
            $table->string('asset_type'); // fixed_asset, plant_equipment
            $table->string('serial_number')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->foreignId('custodian_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->date('date_of_purchase');
            $table->bigInteger('purchase_cost_cents');
            $table->bigInteger('residual_value_cents')->default(0);
            $table->unsignedInteger('useful_life_years');
            $table->string('depreciation_method'); // straight_line, sum_of_digits, diminishing_balance
            $table->unsignedInteger('warranty_years')->nullable();
            $table->string('status')->default('in_use'); // in_use, under_maintenance, disposed, written_off
            $table->text('lifespan_change_reason')->nullable();
            $table->date('schedule_reset_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
