<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** §3.9: "Invoice/ProgressClaim reference" - exactly one of the two. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->foreignId('progress_claim_id')->nullable()->constrained();
            $table->bigInteger('amount_cents');
            $table->text('reason');
            $table->foreignId('approved_by')->constrained('users');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('write_offs');
    }
};
