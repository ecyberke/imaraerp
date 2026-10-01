<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.5: "ProductionOrder — bom_id, sales_order_id (nullable — can be for
 * stock), warehouse_id (where the FG output lands), quantity, status."
 *
 * The extra columns beyond that literal field list
 * (rm_value_actual_cents/fg_value_standard_cents/wastage_variance_cents/
 * bom_variance_pct/bom_variance_exceeded/qc_status) exist because
 * execution_plan.md's own bullet for this branch explicitly asks for
 * "wastage variance against tolerance_pct with bom_variance_exceeded
 * notification" and "QualityCheck (polymorphic GRN/ProductionOrder)" -
 * neither is derivable from the bare four-field list above without
 * somewhere to store the computed result. No separate
 * "ProductionOrderLine" actual-consumption entity is introduced -
 * §3.5 names none, and per-line actual quantities are accepted as a
 * request parameter at completion time (see ProductionOrderService)
 * rather than invented as a new persisted entity; only the aggregate
 * figures needed for the ledger posting and the variance check are
 * stored here.
 *
 * bom_variance_exceeded would fire a real Notification once that
 * entity exists (approval-notification-compliance, not yet built) -
 * flagged rather than silently simulated; this stores the fact so
 * nothing is lost once that branch lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained('bill_of_materials');
            $table->foreignId('sales_order_id')->nullable()->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 18, 4);
            $table->string('status')->default('draft'); // draft, completed, cancelled
            $table->bigInteger('rm_value_actual_cents')->nullable();
            $table->bigInteger('fg_value_standard_cents')->nullable();
            $table->bigInteger('wastage_variance_cents')->nullable();
            $table->decimal('bom_variance_pct', 8, 4)->nullable();
            $table->boolean('bom_variance_exceeded')->default(false);
            $table->string('qc_status')->default('pending'); // pending, passed, failed
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
