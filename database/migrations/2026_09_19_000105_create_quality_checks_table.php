<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.3/§3.5: polymorphic, used by both GRN (incoming RM,
 * checkable_type = StockQuarantine here since GRN itself doesn't exist
 * until procurement) and ProductionOrder (outgoing FG, manufacturing,
 * Phase 2) - one row per inspection, the authoritative record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('checkable_type');
            $table->unsignedBigInteger('checkable_id');
            $table->string('result'); // pass, fail
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disposition'); // accept, rework, scrap
            $table->timestamps();

            $table->index(['tenant_id', 'checkable_type', 'checkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_checks');
    }
};
