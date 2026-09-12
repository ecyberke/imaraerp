<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DummyRecord exists solely to satisfy platform-foundation's exit criterion
 * (execution_plan.md): "a dummy entity can then be created, is correctly
 * tenant-scoped, policy-gated, ... " and specifically to carry the
 * HTTP-level cross-tenant isolation test v20 clarified is required (a real
 * authenticated request, not a query/scope-level assertion). Not an ERP
 * entity — later branches don't reference this table; safe to drop once a
 * real tenant-scoped entity exists to exercise the same test against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dummy_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dummy_records');
    }
};
