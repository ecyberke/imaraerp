<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_line_id')->constrained()->cascadeOnDelete();
            $table->string('period');
            $table->decimal('previous_qty', 18, 4)->default(0);
            $table->decimal('current_qty', 18, 4)->default(0);
            $table->decimal('cumulative_qty', 18, 4)->default(0);
            $table->decimal('certified_qty', 18, 4)->nullable();
            $table->string('status')->default('draft'); // draft | submitted | certified
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_sheets');
    }
};
