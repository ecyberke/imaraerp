<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.4. project_id/boq_id: nullable, no FK yet - Project
 * (projects-milestones-ui) and BOQ (crm-sales-boq) don't exist until
 * later branches in the plan's own ordering, same forward-reference
 * pattern used throughout. No retention_terms_id, deliberately (§3.4:
 * ContractRetentionTerms points AT the Subcontract, never the reverse -
 * querying back would risk a stale FK drifting from the real row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcontracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('boq_id')->nullable();
            $table->json('required_document_types')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontracts');
    }
};
