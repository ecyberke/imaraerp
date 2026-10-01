<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7/§5.4: "party_id, name, specification_file_path (nullable),
 * status." version is the optimistic-locking column §5.4 names
 * explicitly for the DLP-eligibility-job vs manual-closure race: "the
 * job checks the current version before acting and re-reads if it's
 * changed since the job started."
 *
 * dlp_ready_to_close (not in the doc's bare field list) exists because
 * §5.4 describes the DLP-eligibility job surfacing "ready to close" as
 * "a Notification and dashboard item" - Notification doesn't exist yet
 * (approval-notification-compliance, a later Phase 2 branch), so this
 * stores the fact the job computes rather than silently dropping it;
 * flagged, not a silent addition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->string('name');
            $table->string('specification_file_path')->nullable();
            $table->string('status')->default('initiated'); // initiated, in_progress, complete, defects_liability, closed, cancelled
            $table->date('completed_at')->nullable();
            $table->boolean('dlp_ready_to_close')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
