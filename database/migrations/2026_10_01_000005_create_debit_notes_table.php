<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mirrors credit_notes exactly - see that migration's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_number')->nullable();
            $table->foreignId('invoice_id')->constrained();
            $table->bigInteger('amount_cents');
            $table->string('reason');
            $table->string('etr_serial_number')->nullable();
            $table->string('etims_invoice_number')->nullable();
            $table->date('note_date');
            $table->date('posting_date');
            $table->string('status')->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
