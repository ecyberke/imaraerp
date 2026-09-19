<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1: unified customer/supplier/contractor. credit_limit
 * is a monetary field - stored as bigInteger cents (ledger-core's Money
 * decision applied consistently outside the ledger tables too, not just
 * within JournalLine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // customer, supplier, contractor, both
            $table->bigInteger('credit_limit_cents')->default(0);
            $table->string('payment_terms')->nullable(); // credit, cash
            $table->string('tax_residency_status')->nullable(); // resident_certified, resident_uncertified, non_resident
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
