<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12: net_book_value_at_disposal is stored, captured at disposal time -
 * same immutable-historical-snapshot rationale as AssetRevaluation's own
 * stored NBV field, since depreciation stops accruing after disposal and
 * a later "current accumulated_depreciation" read wouldn't reflect that.
 * sold_on_credit isn't in the doc's bare field list but is needed by
 * LedgerPostingService::postAssetDisposed()'s own soldOnCredit parameter
 * (Cash/Bank vs Accounts Receivable) - flagged the same way amount_delta
 * was added to VariationOrder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->date('disposal_date');
            $table->string('disposal_type'); // sold, scrapped, written_off
            $table->bigInteger('sale_proceeds_cents')->default(0);
            $table->boolean('sold_on_credit')->default(false);
            $table->bigInteger('net_book_value_at_disposal_cents');
            $table->bigInteger('gain_loss_amount_cents');
            $table->string('status')->default('completed');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_disposals');
    }
};
