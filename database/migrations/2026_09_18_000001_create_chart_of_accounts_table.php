<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1. Per-tenant rows from one fixed standard seed (same
 * pattern as Role) - seeded on Tenant creation, not shared globally.
 * Postings resolve accounts by `name` (matching every §7 row and the
 * reference LedgerPostingServiceTest.php, which are both name-keyed),
 * so `name` - not `code` - is the real lookup key; `code` exists for
 * human-facing account numbering, not application logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('account_type'); // asset, liability, equity, revenue, expense
            $table->string('sub_type')->nullable();
            $table->foreignId('parent_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_contra')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'account_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
