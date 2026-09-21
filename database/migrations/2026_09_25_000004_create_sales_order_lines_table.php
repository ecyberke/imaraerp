<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct Sale previously had no line-item structure at all in this
     * codebase - a real, load-bearing gap this branch closes.
     */
    public function up(): void
    {
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('boq_line_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->bigInteger('rate_cents');
            $table->bigInteger('amount_cents');
            $table->decimal('quantity_delivered', 18, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
    }
};
