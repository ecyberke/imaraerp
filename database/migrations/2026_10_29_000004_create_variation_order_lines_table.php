<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7: "variation_order_id, boq_line_id (nullable - null for a wholly
 * new item), section_id (nullable - for a variation introducing a new
 * section), variation_type (quantity_change, rate_change, new_item,
 * omission), quantity_delta, rate_delta, amount_delta, description."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_line_id')->nullable()->constrained('boq_lines')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('boq_sections')->nullOnDelete();
            $table->string('variation_type'); // quantity_change, rate_change, new_item, omission
            $table->decimal('quantity_delta', 18, 4)->default(0);
            $table->bigInteger('rate_delta_cents')->default(0);
            $table->bigInteger('amount_delta_cents');
            $table->string('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_order_lines');
    }
};
