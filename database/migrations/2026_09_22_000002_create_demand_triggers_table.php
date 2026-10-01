<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.4. source_id: nullable, no FK - source_type is
 * 'manual', 'reorder_level', or 'manufacturing'; only 'reorder_level'
 * triggers have a real source right now (the reorder-level check itself),
 * 'manufacturing' will reference ProductionOrder once manufacturing
 * exists. warehouse_id added - reorder-level deduplication (§3.4) is
 * meaningless without knowing *which* warehouse's stock fell below the
 * threshold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // manual, reorder_level, manufacturing
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity_needed', 15, 4);
            $table->string('status')->default('open'); // open, fulfilled, cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'item_id', 'warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_triggers');
    }
};
