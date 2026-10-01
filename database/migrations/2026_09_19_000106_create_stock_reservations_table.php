<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT a §3-named entity - a deliberate addition, flagged rather than
 * silently invented. §3.2's SalesOrderReservation and §3.4's
 * RMReservation both need the exact same shape (reserve_type: soft/hard;
 * status: active/consumed/released - §5.3) and the exact same locked
 * check-and-reserve mechanism (§6), but neither SalesOrder nor
 * ProductionOrder exist until crm-sales-boq/manufacturing. inventory-core's
 * own exit criterion (execution_plan.md) requires proving that locked
 * mechanism now, against something real - this generic, reference-agnostic
 * table is that something. When crm-sales-boq/procurement land, each
 * either extends this table (adds its own FK column) or wraps it via
 * reference_type/reference_id - a call for that branch to make, not
 * pre-decided here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('reserve_type'); // soft, hard
            $table->string('status')->default('active'); // active, consumed, released
            $table->decimal('quantity', 15, 4);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_id', 'warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
