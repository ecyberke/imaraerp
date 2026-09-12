<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.10: a row-locked counter table, not a Postgres SEQUENCE
 * (a SEQUENCE can't be rolled back - a gap-free, legally-sequential number
 * needs SELECT ... FOR UPDATE inside the same transaction as the document
 * insert). Scoped per tenant AND per fiscal_year - fiscal_year resets on
 * the Kenyan tax year (1 January) regardless of the tenant's own
 * accounting fiscal year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('prefix');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
