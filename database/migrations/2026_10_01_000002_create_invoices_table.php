<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9's Invoice field list doesn't itself name a sales_order_id (the
 * origin trace is at the line level, via InvoiceLine.source_type/
 * source_id) - but the invoice_policy triggers (§3.2: on_order/
 * on_delivery/on_milestone, crm-sales-boq) need a header-level anchor to
 * know which SalesOrder an Invoice bills, or nothing can actually create
 * one from those transitions. Added here as a flagged, load-bearing
 * necessity, not a silent addition. credit_approval_status defaults
 * 'not_required' for cash invoices (§3.9: credit checking only applies
 * to payment_terms=credit) and 'pending' for credit invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_number')->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained();
            $table->foreignId('party_id')->constrained();
            $table->date('invoice_date');
            $table->date('posting_date');
            $table->string('payment_terms'); // credit | cash
            $table->string('credit_approval_status')->default('not_required'); // not_required, pending, approved, rejected, overridden
            $table->bigInteger('gross_amount_cents');
            $table->bigInteger('vat_amount_cents');
            $table->decimal('retention_percentage', 8, 4)->default(0);
            $table->bigInteger('retention_amount_cents')->default(0);
            $table->bigInteger('net_payable_cents');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes');
            $table->string('etr_serial_number')->nullable();
            $table->string('etims_invoice_number')->nullable();
            $table->string('status')->default('draft'); // draft, raised, partially_paid, paid, written_off
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_opening_balance')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
