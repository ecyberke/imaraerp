<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9: the real, full Payment entity (both directions). procurement's
 * SupplierPayment remains in place as its own already-tested
 * disbursement-only stand-in (flagged there as exactly that) - this
 * branch doesn't retrofit/replace it, to avoid destabilizing procurement's
 * already-merged disbursement flow; consolidating the two is left to a
 * later pass, noted here rather than silently duplicated forever.
 *
 * bank_account_id: forward reference to §3.10's BankAccount, not yet
 * built - nullable, no FK, the established pattern. No invoice_id - a
 * payment settles via PaymentAllocation, never a direct FK (§3.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->string('direction'); // receipt | disbursement
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('settlement_exchange_rate', 18, 6)->nullable();
            $table->bigInteger('amount_cents');
            $table->string('method'); // cash, bank_transfer, cheque, mpesa
            $table->string('mpesa_reference')->nullable();
            $table->string('mpesa_reconciliation_status')->nullable(); // unmatched, matched, disputed
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes');
            $table->bigInteger('wht_amount_cents')->nullable();
            $table->timestamp('received_at');
            $table->date('posting_date');
            $table->boolean('is_opening_balance')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
