<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every project_id column created before Project existed - bill_of_materials
 * (master-data), subcontracts (procurement), sales_orders and
 * boq_import_stagings (crm-sales-boq), resource_assignments
 * (labour-resourcing) - was left as a plain nullable column with no FK,
 * the established forward-reference-then-backfill pattern used
 * throughout this project. All five get their real FK now that
 * projects exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
        Schema::table('subcontracts', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
        Schema::table('boq_import_stagings', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
        Schema::table('resource_assignments', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bill_of_materials', fn (Blueprint $table) => $table->dropForeign(['project_id']));
        Schema::table('subcontracts', fn (Blueprint $table) => $table->dropForeign(['project_id']));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropForeign(['project_id']));
        Schema::table('boq_import_stagings', fn (Blueprint $table) => $table->dropForeign(['project_id']));
        Schema::table('resource_assignments', fn (Blueprint $table) => $table->dropForeign(['project_id']));
    }
};
