<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks every STK Push we initiate, so the async Safaricom callback (an
 * unauthenticated inbound webhook - no Sanctum user, no tenant on the
 * request) can be correlated back to a tenant/party/invoice purely via
 * `checkout_request_id`, which Safaricom echoes back on the callback.
 * This is the actual idempotency boundary for the webhook: a callback is
 * only ever acted on while status is still 'pending' (see MpesaService::
 * handleCallback()), so a duplicate/replayed callback for an
 * already-settled request is a safe no-op rather than a second Payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_stk_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->foreignId('initiated_by')->nullable()->constrained('users');
            $table->bigInteger('amount_cents');
            $table->string('phone_number');
            $table->string('merchant_request_id')->nullable();
            $table->string('checkout_request_id')->nullable()->unique();
            $table->string('status')->default('pending'); // pending, completed, failed, cancelled
            $table->string('result_code')->nullable();
            $table->string('result_desc')->nullable();
            $table->string('mpesa_receipt_number')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->json('raw_callback')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_stk_requests');
    }
};
