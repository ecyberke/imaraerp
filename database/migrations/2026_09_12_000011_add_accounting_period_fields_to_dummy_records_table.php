<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the shape JournalEntry will need (architecture §3.9): a
 * record_date that gets checked against AccountingPeriod, and
 * original_intended_posting_date populated only when the date was
 * auto-forwarded out of a closed/locked period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dummy_records', function (Blueprint $table) {
            $table->date('record_date')->nullable()->after('name');
            $table->foreignId('accounting_period_id')->nullable()->after('record_date')->constrained();
            $table->date('original_intended_posting_date')->nullable()->after('accounting_period_id');
        });
    }

    public function down(): void
    {
        Schema::table('dummy_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accounting_period_id');
            $table->dropColumn(['record_date', 'original_intended_posting_date']);
        });
    }
};
