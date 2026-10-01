<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.10: "user_id, type (reorder_alert, approval_pending, qc_failure,
 * milestone_due, retention_release_due, casual_conversion_due,
 * dlp_ready_to_close, bom_variance_exceeded, hire_invoice_mismatch),
 * entity_type, entity_id, message, channel (in_app, email, sms),
 * sent_at, read_at." delivery_mode isn't in the doc's bare field list -
 * needed to actually implement the named digest behavior ("lower-
 * priority notification types support a batched_daily/batched_weekly
 * delivery mode alongside immediate") - see NotificationService's own
 * per-type config for which types default to which mode, and which
 * stay always-immediate regardless.
 *
 * Eight of the nine types have a real producer wired in this branch
 * (reorder_alert: DemandTriggerService; approval_pending: VariationOrder/
 * PurchaseOrder/CreditApproval's approve()/override(); qc_failure:
 * PurchaseOrderReceivingService; retention_release_due:
 * RetentionReleaseService; casual_conversion_due: TimesheetService;
 * dlp_ready_to_close: ProjectService; bom_variance_exceeded:
 * ProductionOrderService; hire_invoice_mismatch:
 * EquipmentHireContractService). milestone_due has no producer anywhere
 * in this codebase - Milestone (§3.7) has no due/target date field at
 * all to evaluate "due soon" against, a genuine gap flagged rather than
 * invented a field for here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('type');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('message');
            $table->string('channel'); // in_app, email, sms
            $table->string('delivery_mode')->default('immediate'); // immediate, batched_daily, batched_weekly
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
