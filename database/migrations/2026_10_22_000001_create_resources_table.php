<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.6: "type (internal, contractor), party_id (nullable for internal
 * staff), employee_id (nullable - set when type=internal and the
 * person is a payroll employee, §3.11), skill_category." employee_id
 * is a forward reference to Employee (hr-payroll, not yet built) -
 * nullable, no FK, the established pattern used throughout this
 * codebase for entities from later branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // internal, contractor
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('skill_category')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
