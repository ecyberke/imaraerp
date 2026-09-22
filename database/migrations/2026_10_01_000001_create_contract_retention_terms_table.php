<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9: polymorphic contract_type/contract_id (SalesOrder or Subcontract
 * - both real tables by this point). retention_cap is nullable - a
 * contract with no cap is valid (uncapped retention is common enough in
 * practice); cap enforcement only activates when set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_retention_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type');
            $table->unsignedBigInteger('contract_id');
            $table->string('direction'); // receivable | payable
            $table->decimal('retention_percentage', 8, 4);
            $table->bigInteger('retention_cap_cents')->nullable();
            $table->string('first_release_trigger')->default('practical_completion');
            $table->string('second_release_trigger')->default('dlp_end');
            $table->integer('dlp_duration_months')->nullable();
            $table->string('release_trigger_source')->nullable(); // own_dlp | main_contract_dlp (Subcontract only)
            $table->timestamps();

            $table->index(['tenant_id', 'contract_type', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_retention_terms');
    }
};
