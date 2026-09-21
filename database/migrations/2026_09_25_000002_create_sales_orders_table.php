<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.2 names this entity "Quotation / SalesOrder" - one continuous
     * document lifecycle, not two tables. The `status` state machine
     * (§5.1) covers both the pre-approval quotation stage (draft,
     * feasibility_check, quoted) and the post-approval sales-order stage
     * (approved onward); there is no separate conversion step.
     *
     * project_id: forward reference to Project (projects-milestones-ui,
     * not yet built) - nullable, no FK constraint, per the established
     * pattern (see stock_quarantines.grn_id, subcontracts.project_id).
     * Only meaningful for the Project/Manufacture-for-Project supply
     * paths, where §5.1 gates `closed` on Project.status=closed instead
     * of the standard terminal state.
     *
     * boq_id: nullable, no FK yet at this point in the migration order
     * (boqs table is created later in this same branch) - real FK added
     * once that table exists.
     */
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_number')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('boq_id')->nullable();
            $table->string('supply_path'); // direct_sale | manufacture_for_sale | project | manufacture_for_project
            $table->string('feasibility_status')->default('pending'); // pending | passed | failed
            $table->string('invoice_policy'); // on_order | on_delivery | on_milestone
            $table->string('status')->default('draft');
            $table->foreignId('currency_id')->constrained();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
