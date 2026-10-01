<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multiple assessments can be recorded against one SalesOrder over
     * time (re-assessment after conditions change); "latest assessment
     * wins" for SalesOrder.feasibility_status, resolved in the service
     * layer by ordering on assessed_at, not by uniqueness here.
     */
    public function up(): void
    {
        Schema::create('feasibility_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->string('result'); // passed | failed
            $table->text('notes')->nullable();
            $table->text('conditions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feasibility_assessments');
    }
};
