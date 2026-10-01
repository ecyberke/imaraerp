<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8/§3.9: the actual migration mechanism, previously described only in
 * prose ("hybrid - sub-ledger reconstruction plus a single opening
 * JournalEntry") with no entity behind it. Asset/AssetDepreciationEntry
 * backfill (asset-management, not yet built) is explicitly NOT covered
 * by OpeningBalanceBatchService - flagged in that service's docblock,
 * not silently dropped; AR/AP (Invoice/Payment/PaymentAllocation/
 * RetentionAccount), ProgressClaim, and StockLedger reconstruction are
 * fully implemented here since all of those entities exist now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->string('status')->default('draft'); // draft, posted
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_batches');
    }
};
