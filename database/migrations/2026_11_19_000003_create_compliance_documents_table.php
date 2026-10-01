<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.10: "party_id (subcontractor), document_type (insurance,
 * lien_waiver, safety_cert, tax_compliance), issue_date, expiry_date,
 * file_path, status (valid, expiring_soon, expired)." status is
 * recomputed at read time from expiry_date (see ComplianceDocumentService),
 * not trusted as a stored fact that could drift - stored only so it's
 * queryable without a full table scan recomputing every row's status
 * from today's date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->string('document_type'); // insurance, lien_waiver, safety_cert, tax_compliance
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status')->default('valid'); // valid, expiring_soon, expired
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_documents');
    }
};
