<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12: "same depreciation fields as Asset but scoped to one component of
 * a composite asset. Total depreciation for the parent is the sum of its
 * components' depreciation, not one blended schedule." When an Asset has
 * one or more AssetComponent rows, DepreciationRunService depreciates each
 * component independently instead of the parent's own purchase_cost/
 * useful_life_years/depreciation_method fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->bigInteger('purchase_cost_cents');
            $table->bigInteger('residual_value_cents')->default(0);
            $table->unsignedInteger('useful_life_years');
            $table->string('depreciation_method');
            $table->text('lifespan_change_reason')->nullable();
            $table->date('schedule_reset_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_components');
    }
};
