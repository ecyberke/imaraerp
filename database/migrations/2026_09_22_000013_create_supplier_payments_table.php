<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT a §3-named entity - the full Payment model (direction, receipt-side
 * WHT/PaymentAllocation fields) is §3.9's job, built in finance-billing.
 * procurement's own exit criterion explicitly needs the disbursement
 * half working now (payment_made / fx_gain_loss postings, settlement_
 * exchange_rate for foreign-currency purchases) - this is that half
 * only, flagged here the same way stock_reservations was in
 * inventory-core, for finance-billing to extend or fold in later rather
 * than pre-deciding that branch's schema now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->string('reference_type'); // PurchaseOrder, Subcontract, ProgressClaim
            $table->unsignedBigInteger('reference_id');
            $table->bigInteger('amount_cents'); // in the referenced document's own currency
            $table->decimal('settlement_exchange_rate', 15, 6)->nullable();
            $table->bigInteger('wht_withheld_cents')->nullable();
            $table->string('method')->nullable(); // cash, bank_transfer, cheque, mpesa
            $table->timestamp('paid_at')->useCurrent();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
