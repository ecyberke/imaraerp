<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1. standard_cost is only meaningful when
 * Category.valuation_method = standard_cost; stored as bigInteger cents
 * (nullable) - only changes via an explicit revision, never as a side
 * effect of a single purchase (the variance goes to Purchase Price
 * Variance instead, per ledger-core's postGrnReceiptStandardCost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->foreignId('category_id')->constrained();
            $table->string('type'); // raw_material, finished_good
            $table->foreignId('uom_id')->constrained('units_of_measure');
            $table->decimal('reorder_level', 15, 4)->default(0);
            $table->bigInteger('standard_cost_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
