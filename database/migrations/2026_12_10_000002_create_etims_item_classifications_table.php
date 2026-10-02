<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KRA's own item classification code list (itemClsCd), synced via
 * EtimsService::syncItemClassifications() (Basic Data Management /
 * selectItemClsList) - every eTIMS item registration needs one of these
 * codes, and they're KRA-assigned, not something this app can invent.
 * Kept as a local cache so Item registration (the etims-integration
 * branch's next step, not built here) can offer a real picker instead of
 * a free-text code field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etims_item_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etims_item_classifications');
    }
};
