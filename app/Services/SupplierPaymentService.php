<?php

namespace App\Services;

use App\Models\Party;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The disbursement half of §3.9's Payment (see supplier_payments
 * migration's docblock for why this isn't the full Payment entity yet).
 * Two distinct paths, matching ledger-core's two distinct posting
 * methods: a same-currency (or no-FX-movement) settlement, and a
 * foreign-currency settlement where AP was booked at the GRN's exchange
 * rate but cash moves at a different settlement_exchange_rate (§3.4/§7's
 * fx_gain_loss).
 */
class SupplierPaymentService
{
    public function __construct(private LedgerPostingService $ledger)
    {
    }

    public function pay(
        Party $party,
        string $referenceType,
        int $referenceId,
        Money $apAmountSettled,
        ?Money $whtWithheldNow = null,
        ?string $method = null,
    ): SupplierPayment {
        return DB::transaction(function () use ($party, $referenceType, $referenceId, $apAmountSettled, $whtWithheldNow, $method) {
            $tenant = Tenant::findOrFail($party->tenant_id);
            $reference = "{$referenceType}-{$referenceId}";

            $entryInput = $this->ledger->postPaymentMade($reference, $apAmountSettled, $whtWithheldNow);
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today());

            return SupplierPayment::create([
                'tenant_id' => $party->tenant_id,
                'party_id' => $party->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'amount_cents' => $apAmountSettled,
                'wht_withheld_cents' => $whtWithheldNow,
                'method' => $method,
                'paid_at' => BusinessTime::now(),
                'journal_entry_id' => $entry->id,
            ]);
        });
    }

    public function payWithFxSettlement(
        Party $party,
        string $referenceType,
        int $referenceId,
        Money $apPortionSettledAtBookedRate,
        Money $cashDisbursedAtSettlementRate,
        string $settlementExchangeRate,
        ?string $method = null,
    ): SupplierPayment {
        return DB::transaction(function () use ($party, $referenceType, $referenceId, $apPortionSettledAtBookedRate, $cashDisbursedAtSettlementRate, $settlementExchangeRate, $method) {
            $tenant = Tenant::findOrFail($party->tenant_id);
            $reference = "{$referenceType}-{$referenceId}";

            $entryInput = $this->ledger->postFxSettlement($reference, $apPortionSettledAtBookedRate, $cashDisbursedAtSettlementRate);
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today());

            return SupplierPayment::create([
                'tenant_id' => $party->tenant_id,
                'party_id' => $party->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'amount_cents' => $apPortionSettledAtBookedRate,
                'settlement_exchange_rate' => $settlementExchangeRate,
                'method' => $method,
                'paid_at' => BusinessTime::now(),
                'journal_entry_id' => $entry->id,
            ]);
        });
    }
}
