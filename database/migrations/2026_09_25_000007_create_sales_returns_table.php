<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promoted to Phase 1 per execution_plan.md: a return that only
     * reverses revenue via Credit Note and never touches inventory
     * leaves stock permanently understated, so this must exist alongside
     * SalesOrder/Delivery, not be deferred.
     *
     * credit_note_id: forward reference to finance-billing's CreditNote
     * (doesn't exist yet) - nullable, no FK, same pattern as every prior
     * branch's forward references.
     */
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained();
            $table->foreignId('sales_order_line_id')->constrained();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity_returned', 18, 4);
            $table->string('reason')->nullable();
            $table->string('restock_status')->default('pending'); // pending | restocked | scrapped
            $table->unsignedBigInteger('credit_note_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
