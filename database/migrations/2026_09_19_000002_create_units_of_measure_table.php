<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1: "conversion factors where needed (e.g., bags↔kg)".
 * base_unit_id + conversion_factor: null on a base unit itself (e.g.
 * "kg"); on a derived unit (e.g. "Bag (50kg)") base_unit_id points at
 * "kg" and conversion_factor is how many base units one of this unit
 * equals (50).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->foreignId('base_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 6)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
