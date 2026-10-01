<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not a §3-named entity - currency_id is referenced throughout §3.4/§3.9
 * (PurchaseOrder, LandedCost, ...) but the doc never defines the lookup
 * table backing it, the same class of gap Warehouse had in
 * inventory-core. KES is always the base currency (stock_ledger.unit_cost
 * and every ledger posting is base-currency-only per §3.4/§7) - seeded
 * per tenant alongside everything else in Tenant::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code'); // ISO 4217, e.g. KES, USD
            $table->string('name');
            $table->boolean('is_base')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
