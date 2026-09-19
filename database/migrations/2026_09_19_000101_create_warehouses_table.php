<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §12 item #4 (resolved): multi-warehouse required from day
 * one. warehouse_id is mandatory on stock_ledger, with "Main Warehouse"
 * seeded as the v1 default. §3.3 also names a designated "In Transit"
 * warehouse that transfer_out/transfer_in movements land goods in/out of.
 * No explicit field list for Warehouse itself exists anywhere in §3 (only
 * referenced by warehouse_id) - this is the minimal shape those two
 * seeded rows and every stock_ledger FK actually need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_in_transit')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
