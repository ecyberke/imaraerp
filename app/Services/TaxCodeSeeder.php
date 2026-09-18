<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Architecture §3.9: multiple WHT TaxCodes per payee type, not a single
 * rate - resident-with-certificate (3%), resident-without-certificate
 * (5%), non-resident (20%) - plus the standard VAT rate. Per-tenant, same
 * pattern as Role/ChartOfAccounts.
 */
final class TaxCodeSeeder
{
    public static function seed(Tenant $tenant): void
    {
        $now = now();

        $vatPayableId = DB::table('chart_of_accounts')
            ->where('tenant_id', $tenant->getKey())->where('name', 'VAT Payable')->value('id');
        $whtPayableId = DB::table('chart_of_accounts')
            ->where('tenant_id', $tenant->getKey())->where('name', 'WHT Payable')->value('id');

        DB::table('tax_codes')->insert([
            [
                'tenant_id' => $tenant->getKey(), 'code' => 'VAT_STANDARD', 'rate' => 0.1600,
                'account_id' => $vatPayableId, 'type' => 'vat', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenant->getKey(), 'code' => 'WHT_RESIDENT_3', 'rate' => 0.0300,
                'account_id' => $whtPayableId, 'type' => 'withholding', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenant->getKey(), 'code' => 'WHT_RESIDENT_5', 'rate' => 0.0500,
                'account_id' => $whtPayableId, 'type' => 'withholding', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenant->getKey(), 'code' => 'WHT_NONRESIDENT_20', 'rate' => 0.2000,
                'account_id' => $whtPayableId, 'type' => 'withholding', 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }
}
