<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5.2/procurement's own flagged gap: "Full ApprovalLimit-gated approval
 * chain is a later branch - a PO is created directly in 'ordered' status
 * here, skipping the intermediate pending_approval/approved states this
 * branch doesn't build the approval mechanism for yet." Resolved here -
 * PurchaseOrderService now drives requisitioned -> pending_approval ->
 * approved -> ordered for real, with approved_by/second_approved_by
 * mirroring VariationOrder's own pattern. PurchaseRequisition gets the
 * same pair for its own single-step (no-amount, see approval_limits
 * migration) approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('second_approved_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('second_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['second_approved_by']);
            $table->dropColumn(['approved_by', 'second_approved_by', 'approved_at']);
        });
    }
};
