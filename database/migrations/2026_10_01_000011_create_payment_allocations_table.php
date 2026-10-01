<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9: invoice_id = null *is* the Customer Advance - no separate
 * entity. status distinguishes a live allocation from one that's since
 * been refunded (a refund doesn't delete the row - the audit trail of
 * "this was once an advance" must survive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->bigInteger('amount_allocated_cents');
            $table->string('status')->default('allocated'); // allocated, refunded
            $table->boolean('is_opening_balance')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
