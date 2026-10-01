<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.4: wht_amount is computed and STORED at certification,
 * never recalculated later even if rates change (same immutability
 * principle as stock valuation layers). journal_entry_id links the
 * certification's posted JournalEntry - needed so a later reversal can
 * find exactly what to reverse via LedgerPostingService::reverse().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcontract_id')->constrained();
            $table->string('period');
            $table->bigInteger('amount_claimed_cents');
            $table->bigInteger('amount_certified_cents')->nullable();
            $table->bigInteger('vat_amount_cents')->nullable();
            $table->bigInteger('retention_amount_cents')->nullable();
            $table->foreignId('wht_tax_code_id')->nullable()->constrained('tax_codes');
            $table->bigInteger('wht_amount_cents')->nullable();
            $table->bigInteger('net_payable_cents')->nullable();
            $table->string('subcontractor_invoice_number')->nullable();
            $table->date('subcontractor_invoice_date')->nullable();
            $table->string('status')->default('submitted'); // submitted, certified, disputed, reversed
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'subcontract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_claims');
    }
};
