<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7: "AnalyticAccount handles per-project financial rollups" - but
 * nothing anywhere in this codebase ever actually links a Project to one;
 * ReportingService::projectPnl()/incomeStatement() take an AnalyticAccount
 * passed in by the caller, with no stored mapping to resolve it from a
 * Project. Needed concretely now by AssetAssignmentService's internal
 * equipment charge posting (§3.12/§7: "Dr Project Equipment Cost, tagged
 * with the project's analytic_account_id"), so it's added here rather
 * than worked around locally - ProjectService::create() (projects-
 * milestones-ui, retrofitted in this branch) now provisions one
 * AnalyticAccount per Project automatically, closing the gap for every
 * future project rather than just this branch's own need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('analytic_account_id')->nullable()->after('party_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['analytic_account_id']);
            $table->dropColumn('analytic_account_id');
        });
    }
};
