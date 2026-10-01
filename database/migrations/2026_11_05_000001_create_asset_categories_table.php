<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12: "category_id (AssetCategory)." A plain grouping lookup, distinct
 * from the inventory `Category` model - that one carries `valuation_method`,
 * an Item/stock-costing concept with no meaning for a depreciating Fixed
 * Asset, so this is its own small table rather than a reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
