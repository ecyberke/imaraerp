<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7: "project_id, subcontract_id (nullable), milestone_id (nullable),
 * description, severity (minor, major, critical), blocks_retention
 * (boolean, set at review), reported_by, reported_date, target_fix_date,
 * status (open, in_progress, rectified, verified, closed)."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('subcontract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('severity'); // minor, major, critical
            $table->boolean('blocks_retention')->default(false);
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reported_date');
            $table->date('target_fix_date')->nullable();
            $table->string('status')->default('open'); // open, in_progress, rectified, verified, closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defects');
    }
};
