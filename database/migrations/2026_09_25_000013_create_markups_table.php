<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boq_id is always required (every markup belongs to a BOQ);
     * section_id is the optional override - null means "applies BOQ-wide",
     * set means "applies to this section only".
     */
    public function up(): void
    {
        Schema::create('markups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('boq_sections')->nullOnDelete();
            $table->string('name');
            $table->decimal('percentage', 8, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markups');
    }
};
