<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7: "project_id, sequence, description, billing_amount (derived,
 * not entered freestanding), boq_line_allocations (many-to-many,
 * BOQLine <-> Milestone with a percentage_of_value per line), billing_locked
 * (boolean, set true once status reaches invoiced), status (pending,
 * utilized, signed_off, rework_required, invoiced, closed)."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained();
            $table->integer('sequence');
            $table->string('description');
            $table->bigInteger('billing_amount_cents')->default(0);
            $table->boolean('billing_locked')->default(false);
            $table->string('status')->default('pending'); // pending, utilized, signed_off, rework_required, invoiced, closed
            $table->timestamps();
        });

        // §3.7: "BOQLine <-> Milestone with a percentage_of_value per
        // line - the single primitive." Bulk section-level allocation
        // (§3.7's UI pattern) expands to one row per line at allocation
        // time, not a separate mechanism.
        Schema::create('milestone_boq_line_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_line_id')->constrained();
            $table->decimal('percentage_of_value', 8, 4);
            $table->timestamps();

            $table->unique(['milestone_id', 'boq_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_boq_line_allocations');
        Schema::dropIfExists('milestones');
    }
};
