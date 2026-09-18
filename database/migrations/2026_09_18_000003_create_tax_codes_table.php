<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.9: multiple WHT codes per payee type, not a single rate
 * - WHT_RESIDENT_3 (3%), WHT_RESIDENT_5 (5%), WHT_NONRESIDENT_20 (20%),
 * plus VAT_STANDARD (16%). `rate` stored as a decimal string (never
 * float) - resolved by TaxCode::rateAsString() into Money::multiplyByRate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->decimal('rate', 6, 4); // e.g. 0.1600 for 16%
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('type'); // vat, withholding
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
