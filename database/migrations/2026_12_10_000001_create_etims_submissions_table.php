<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every outbound OSCU call this app makes, not just sales
 * - KRA's response envelope for most of these calls isn't published in
 * any official example (see EtimsService's own docblock), so every raw
 * response is kept here in full, letting an admin (or a later fix to the
 * field-extraction logic) recover anything EtimsService's best-effort
 * parsing missed, rather than that detail being lost the moment the HTTP
 * response is discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etims_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // device_init, item_classification_sync
            $table->string('status')->default('pending'); // pending, success, failed
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('result_code')->nullable();
            $table->string('result_desc')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etims_submissions');
    }
};
