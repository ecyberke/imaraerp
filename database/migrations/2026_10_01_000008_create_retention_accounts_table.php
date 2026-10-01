<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9: replaces the earlier single-direction RetentionHeld design.
 * balance is deliberately NOT a stored column - "balance (computed)" -
 * derived as amount_cents - released_amount_cents wherever it's needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type');
            $table->unsignedBigInteger('contract_id');
            $table->foreignId('party_id')->constrained();
            $table->string('direction'); // receivable | payable
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->foreignId('progress_claim_id')->nullable()->constrained();
            $table->bigInteger('amount_cents');
            $table->bigInteger('released_amount_cents')->default(0);
            $table->boolean('is_opening_balance')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'contract_type', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_accounts');
    }
};
