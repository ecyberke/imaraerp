<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7: "project_id, variation_number, description, status (draft,
 * submitted, approved, rejected, executed), approved_by,
 * second_approved_by (nullable), submitted_date, approved_date,
 * executed_date (nullable)." amount_delta is not in the doc's bare
 * field list but is needed to look up ApprovalLimit's threshold at
 * submission time (§3.10) - computed as the sum of the VO's own lines,
 * stored for quick reference rather than summed on every read.
 *
 * ApprovalLimit itself doesn't exist yet (approval-notification-
 * compliance, a later Phase 2 branch) - VariationOrderService accepts
 * an explicit approver instead of looking ApprovalLimit up, the same
 * forward-reference-parameter pattern procurement's ProgressClaimService
 * used for ContractRetentionTerms before that existed either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained();
            $table->string('variation_number');
            $table->string('description');
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected, executed
            $table->bigInteger('amount_delta_cents')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_date')->nullable();
            $table->timestamp('approved_date')->nullable();
            $table->timestamp('executed_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_orders');
    }
};
