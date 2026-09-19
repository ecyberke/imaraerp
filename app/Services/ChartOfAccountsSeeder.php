<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Architecture §3.1's standard Chart of Accounts seed - "so the first
 * client can post a journal entry on day one rather than discovering
 * there's no default to fall back to." Per-tenant rows (same pattern as
 * Role), seeded on Tenant creation - every LedgerPostingService posting
 * method resolves accounts by `name` against exactly this list, so this
 * seed and §7's account names must stay in sync (they're copy-matched
 * from the doc, not independently invented).
 *
 * A row missing here that a posting references surfaces loudly, at
 * commit() time, as an InvalidArgumentException - not a silent failure.
 *
 * `parent_account_id` for the statutory payables (PAYE/NSSF/SHIF/Housing/
 * HELB/NITA/WHT Payable) is left null: §3.1's field description mentions
 * them "rolling up to a single 'Statutory Payables' line for
 * presentation" via parent_account_id, but the doc's own seed list names
 * no such parent account to roll up to. Grouped instead by
 * sub_type='statutory_payable' for reporting - the same mechanism
 * Retention Receivable/Payable already use without a parent row. Worth
 * confirming if a real "Statutory Payables" parent account was intended
 * but omitted from the seed list.
 */
final class ChartOfAccountsSeeder
{
    /**
     * @return array<int, array{name: string, type: string, sub_type: ?string, is_contra: bool, parent: ?string}>
     */
    public static function definitions(): array
    {
        return [
            // --- Assets ---
            ['name' => 'Cash/Bank', 'type' => 'asset', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Accounts Receivable', 'type' => 'asset', 'sub_type' => 'receivable', 'is_contra' => false, 'parent' => null],
            ['name' => 'Retention Receivable', 'type' => 'asset', 'sub_type' => 'retention_receivable', 'is_contra' => false, 'parent' => null],
            ['name' => 'VAT Input/Receivable', 'type' => 'asset', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'WHT Receivable/Tax Credit', 'type' => 'asset', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Inventory (RM)', 'type' => 'asset', 'sub_type' => 'inventory', 'is_contra' => false, 'parent' => null],
            ['name' => 'Inventory (FG)', 'type' => 'asset', 'sub_type' => 'inventory', 'is_contra' => false, 'parent' => null],
            ['name' => 'Fixed Assets at Cost', 'type' => 'asset', 'sub_type' => 'fixed_asset', 'is_contra' => false, 'parent' => null],
            ['name' => 'Accumulated Depreciation', 'type' => 'asset', 'sub_type' => null, 'is_contra' => true, 'parent' => 'Fixed Assets at Cost'],

            // --- Liabilities ---
            ['name' => 'Accounts Payable', 'type' => 'liability', 'sub_type' => 'payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'Retention Payable', 'type' => 'liability', 'sub_type' => 'retention_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'VAT Payable', 'type' => 'liability', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'PAYE Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'NSSF Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'SHIF Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'Housing Levy Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'HELB Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'NITA Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'WHT Payable', 'type' => 'liability', 'sub_type' => 'statutory_payable', 'is_contra' => false, 'parent' => null],
            ['name' => 'Other Deductions Payable', 'type' => 'liability', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Net Pay Payable', 'type' => 'liability', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Customer Advance', 'type' => 'liability', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Landed Cost Payable', 'type' => 'liability', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Loan Payable', 'type' => 'liability', 'sub_type' => null, 'is_contra' => false, 'parent' => null],

            // --- Equity ---
            ['name' => 'Retained Earnings', 'type' => 'equity', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Retained Earnings (Opening)', 'type' => 'equity', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Asset Revaluation Reserve', 'type' => 'equity', 'sub_type' => 'equity_reserve', 'is_contra' => false, 'parent' => null],
            ['name' => 'Owner Capital', 'type' => 'equity', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Drawings', 'type' => 'equity', 'sub_type' => null, 'is_contra' => true, 'parent' => 'Owner Capital'],

            // --- Revenue ---
            ['name' => 'Revenue', 'type' => 'revenue', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Gain on Disposal', 'type' => 'revenue', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'FX Gain', 'type' => 'revenue', 'sub_type' => null, 'is_contra' => false, 'parent' => null],

            // --- Expenses ---
            ['name' => 'COGS', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Salary/Wages Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Employer Statutory Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'NITA Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Depreciation Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Subcontract Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Scrap/Variance Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Wastage Variance Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            // Favorable-variance counterpart - a peer P&L line, not a true
            // contra-expense (§3.1 is explicit this one doesn't carry
            // is_contra/parent_account_id the way Internal Equipment
            // Recovery below does, despite looking similar).
            ['name' => 'Wastage Variance', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Bad Debt Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'FX Loss', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Loss on Disposal', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'P&L Impairment Expense', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Purchase Price Variance', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Project Equipment Cost', 'type' => 'expense', 'sub_type' => null, 'is_contra' => false, 'parent' => null],
            ['name' => 'Internal Equipment Recovery', 'type' => 'expense', 'sub_type' => null, 'is_contra' => true, 'parent' => 'Project Equipment Cost'],
        ];
    }

    public static function seed(Tenant $tenant): void
    {
        $now = now();
        $codesByType = ['asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'revenue' => 4000, 'expense' => 5000];
        $nextCode = $codesByType;

        // Two passes: parents/standalone accounts first (so their ids
        // exist), then contra accounts, which reference a parent by name.
        $definitions = self::definitions();
        $nameToId = [];

        foreach ($definitions as $def) {
            if ($def['parent'] !== null) {
                continue;
            }
            $code = (string) $nextCode[$def['type']]++;
            $id = DB::table('chart_of_accounts')->insertGetId([
                'tenant_id' => $tenant->getKey(),
                'code' => $code,
                'name' => $def['name'],
                'account_type' => $def['type'],
                'sub_type' => $def['sub_type'],
                'parent_account_id' => null,
                'is_contra' => $def['is_contra'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $nameToId[$def['name']] = $id;
        }

        foreach ($definitions as $def) {
            if ($def['parent'] === null) {
                continue;
            }
            $code = (string) $nextCode[$def['type']]++;
            DB::table('chart_of_accounts')->insert([
                'tenant_id' => $tenant->getKey(),
                'code' => $code,
                'name' => $def['name'],
                'account_type' => $def['type'],
                'sub_type' => $def['sub_type'],
                'parent_account_id' => $nameToId[$def['parent']] ?? null,
                'is_contra' => $def['is_contra'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
