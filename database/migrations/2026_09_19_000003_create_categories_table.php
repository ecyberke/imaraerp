<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1: hierarchical, industry-agnostic. valuation_method
 * set per category (fifo/weighted_average/standard_cost), not
 * system-wide - so imported materials can run FIFO while locally
 * sourced ones run weighted average in the same tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('parent_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('valuation_method'); // fifo, weighted_average, standard_cost
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
