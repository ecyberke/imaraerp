<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('boq_sections')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->bigInteger('rate_cents');
            $table->bigInteger('amount_cents');
            $table->string('line_type')->default('measured'); // measured | provisional_sum | prime_cost
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_lines');
    }
};
