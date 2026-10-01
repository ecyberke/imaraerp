<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12: for hired-in, non-owned plant - "party_id (hire company),
 * description, project_id, hire_rate, hire_start_date, hire_end_date
 * (nullable), expected_days (computed from the assignment dates)." The
 * discrepancy check ("hire company's invoice/PO line claims a different
 * number of days than expected_days") is specified as surfacing via a
 * `hire_invoice_mismatch` Notification - Notification doesn't exist yet
 * (deferred to approval-notification-compliance), so hire_invoice_mismatch
 * is stored as a boolean flag here instead, the same stand-in pattern
 * already used for ProductionOrder.bom_variance_exceeded and
 * Project.dlp_ready_to_close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_hire_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->string('description');
            $table->foreignId('project_id')->constrained();
            $table->bigInteger('hire_rate_cents');
            $table->date('hire_start_date');
            $table->date('hire_end_date')->nullable();
            $table->unsignedInteger('invoiced_days')->nullable();
            $table->boolean('hire_invoice_mismatch')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_hire_contracts');
    }
};
