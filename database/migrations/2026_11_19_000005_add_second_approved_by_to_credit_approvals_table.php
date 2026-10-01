<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ApprovalLimit (entity_type=credit_approval) gates CreditApprovalService::
 * override() - a large override can now require a genuinely different
 * second approver, same maker-checker rule as every other ApprovalLimit-
 * gated action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_approvals', function (Blueprint $table) {
            $table->foreignId('second_approved_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_approvals', function (Blueprint $table) {
            $table->dropForeign(['second_approved_by']);
            $table->dropColumn('second_approved_by');
        });
    }
};
