<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.9: early_release_reason is required whenever a manual override
 * fires before the automatic pending->ready conditions clear -
 * enforced in RetentionReleaseService, not a DB constraint (the
 * "required only conditionally" rule doesn't map to a plain NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retention_account_id')->constrained();
            $table->string('stage'); // practical_completion | dlp_end
            $table->bigInteger('amount_cents');
            $table->timestamp('released_at')->nullable();
            $table->string('status')->default('pending'); // pending, blocked_defects, blocked_client_signoff, blocked_dispute, ready, released
            $table->text('block_reason')->nullable();
            $table->text('early_release_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_releases');
    }
};
