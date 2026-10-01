<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.10: "role_id, entity_type (PR, PO, credit_approval, variation_order),
 * max_amount, requires_second_approval_above, second_approver_role_id
 * (nullable, required when requires_second_approval_above is set -
 * without this, 'second approval' is satisfiable by any second user with
 * the same role, which is a real control gap)." ApprovalLimitService
 * additionally enforces the same-user maker-checker rule this doc also
 * names (a second approval must come from a literally different User,
 * not just a different qualifying role) - that check has no column here,
 * it's a runtime comparison against whoever actually submitted first.
 *
 * purchase_requisition has no monetary value anywhere in this schema
 * (PurchaseRequisitionLine carries quantity_needed, never a cost - price
 * only exists once a PurchaseOrder is drafted against it) - its
 * ApprovalLimit rows gate WHO may approve (role must have a row for this
 * entity_type) with max_amount/requires_second_approval_above evaluated
 * against Money::zero(), which both the limit check and the second-
 * approval threshold trivially satisfy. Flagged, not silently guessed:
 * the doc names PR as one of the four gated entity_types without
 * reconciling that PR carries no amount to gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->string('entity_type'); // purchase_requisition, purchase_order, credit_approval, variation_order
            $table->bigInteger('max_amount_cents');
            $table->bigInteger('requires_second_approval_above_cents')->nullable();
            $table->foreignId('second_approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'role_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_limits');
    }
};
