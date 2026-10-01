<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "Closes a real gap - every PayrollRun and every subcontractor
 * WHT posting creates a payable to one of these authorities, and nothing
 * in the model previously reduced it." Same clearing pattern as the
 * "Net pay disbursed" row for Net Pay Payable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->string('authority'); // KRA-PAYE, NSSF, SHIF, HELB, NITA, KRA-WHT
            $table->bigInteger('amount_cents');
            $table->string('reference_number')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status')->default('pending'); // pending, paid
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_remittances');
    }
};
