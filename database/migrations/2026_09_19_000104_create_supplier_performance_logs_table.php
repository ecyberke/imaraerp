<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.3. po_id: nullable, no FK - PurchaseOrder doesn't
 * exist until procurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_performance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->unsignedBigInteger('po_id')->nullable();
            $table->string('event_type'); // on_time, late, qc_failed, returned
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_performance_logs');
    }
};
