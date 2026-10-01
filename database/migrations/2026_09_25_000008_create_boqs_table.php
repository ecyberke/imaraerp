<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.8: BOQ is polymorphic across Project/Subcontract. Project
     * doesn't exist yet (projects-milestones-ui) - boqable_type/id is a
     * plain polymorphic pair (no FK constraint is possible on a
     * polymorphic column anyway), so this needs no forward-reference
     * workaround beyond what Laravel's morph columns already are.
     */
    public function up(): void
    {
        Schema::create('boqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('boqable_type');
            $table->unsignedBigInteger('boqable_id');
            $table->bigInteger('revised_contract_value_cents')->nullable();
            $table->boolean('uses_sections')->default(false);
            $table->string('source')->default('manual'); // manual | uploaded | generated
            $table->string('source_file_path')->nullable();
            $table->string('status')->default('draft'); // draft | active | closed
            $table->timestamps();

            $table->index(['tenant_id', 'boqable_type', 'boqable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boqs');
    }
};
