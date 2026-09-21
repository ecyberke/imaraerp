<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.8's staging table: an upload lands here row-by-row and only
     * becomes a real BOQLine after explicit confirmation - never written
     * directly to boq_lines. project_id is a forward reference (Project
     * doesn't exist yet) - nullable, no FK, same established pattern;
     * boq_id is nullable because staging can precede the BOQ itself
     * being created (the confirm step can create it).
     */
    public function up(): void
    {
        Schema::create('boq_import_stagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('boq_id')->nullable()->constrained('boqs')->nullOnDelete();
            $table->string('source_file_path')->nullable();
            $table->json('raw_row_data');
            $table->foreignId('mapped_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('mapped_section')->nullable();
            $table->decimal('mapped_quantity', 18, 4)->nullable();
            $table->bigInteger('mapped_rate_cents')->nullable();
            $table->string('status')->default('pending_review'); // pending_review | confirmed | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('boq_line_id')->nullable()->constrained('boq_lines')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_import_stagings');
    }
};
