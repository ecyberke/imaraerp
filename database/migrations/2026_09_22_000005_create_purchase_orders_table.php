<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.4/§5.2. status state machine: requisitioned ->
 * pending_approval -> approved -> ordered -> partially_received ->
 * received -> quarantined -> (qc_passed -> stocked | qc_failed ->
 * returned). requisition_id is a plain FK (not unique) - many POs can
 * reference one PR (§3.4's explicit cardinality note).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->string('status')->default('requisitioned');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
