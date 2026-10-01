<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_section_id')->nullable()->constrained('boq_sections')->nullOnDelete();
            $table->string('name');
            $table->integer('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_sections');
    }
};
