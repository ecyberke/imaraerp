<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_of_material_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_of_materials_id')->constrained('bill_of_materials')->cascadeOnDelete();
            $table->foreignId('raw_material_item_id')->constrained('items');
            $table->decimal('quantity', 15, 4);
            $table->timestamps();

            $table->index('bill_of_materials_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_of_material_lines');
    }
};
