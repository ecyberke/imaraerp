<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9: polymorphic lineable_type/lineable_id - Invoice, CreditNote, and
 * DebitNote all share this same line shape per KRA's line-item
 * requirement. source_type/source_id trace back to the SalesOrderLine/
 * BOQLine that generated the line, nullable for lines with no such
 * origin (e.g. a manually-added invoice line).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('lineable_type');
            $table->unsignedBigInteger('lineable_id');
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('line_total_cents');
            $table->bigInteger('vat_amount_cents');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lineable_type', 'lineable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
