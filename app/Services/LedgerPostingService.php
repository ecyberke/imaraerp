<?php

namespace App\Services;

use App\Models\AnalyticAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ledger\EntryInput;
use App\Services\Ledger\LineInput;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Real (Eloquent + PostgreSQL) implementation of every §7 row, ported from
 * LedgerPostingServiceTest.php's reference LedgerPostingService. Every
 * postXxx() method here is a close, line-for-line port of its reference
 * counterpart: same name, same Dr/Cr shape, same formulas - Money instead
 * of float is the one substantive change (execution_plan.md's own
 * required decision), plus accounts are still referenced by the exact
 * same name strings §7 uses, resolved to a real ChartOfAccount only at
 * commit() time.
 *
 * Each postXxx() method returns a pure, unpersisted EntryInput - nothing
 * touches the database until commit() persists it (tenant/accounting-
 * period/account resolution happens there, once, generically, rather
 * than 36 times over).
 */
class LedgerPostingService
{
    /** @var array<string, int> in-request cache: "{tenant_id}:{type}:{name}" -> id */
    private array $resolvedIds = [];

    public function __construct(private AccountingPeriodResolver $periods) {}

    // =========================================================================
    // Persistence — resolves accounts/period, writes JournalEntry + JournalLine
    // rows inside one transaction. Every postXxx() method below funnels
    // through this; none of them touch the database directly.
    // =========================================================================

    public function commit(
        Tenant $tenant,
        EntryInput $input,
        CarbonInterface $intendedPostingDate,
        ?User $createdBy = null,
        ?int $referenceId = null,
    ): JournalEntry {
        return DB::transaction(function () use ($tenant, $input, $intendedPostingDate, $createdBy, $referenceId) {
            $resolution = $this->periods->resolve($tenant, $intendedPostingDate);

            $entry = JournalEntry::create([
                'tenant_id' => $tenant->id,
                'event_type' => $input->eventType,
                'reference_type' => $input->referenceType,
                'reference_id' => $referenceId,
                'external_reference' => $input->externalReference,
                'posting_date' => $resolution->postingDate,
                'original_intended_posting_date' => $resolution->originalIntendedPostingDate,
                'accounting_period_id' => $resolution->period->id,
                'created_by' => $createdBy?->id,
            ]);

            foreach ($input->lines as $line) {
                JournalLine::create([
                    'tenant_id' => $tenant->id,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $this->resolveAccountId($tenant, $line->account),
                    'analytic_account_id' => $line->analyticAccountCode
                        ? $this->resolveAnalyticAccountId($tenant, $line->analyticAccountCode)
                        : null,
                    'tax_code_id' => $line->taxCode
                        ? $this->resolveTaxCodeId($tenant, $line->taxCode)
                        : null,
                    'debit_cents' => $line->debit,
                    'credit_cents' => $line->credit,
                ]);
            }

            return $entry->load('lines.account', 'lines.analyticAccount', 'lines.taxCode');
        });
    }

    /**
     * §3.9: "The posting service exposes reverse(JournalEntry $entry,
     * string $reason)" - a new entry with debits/credits swapped, posted
     * through the normal AccountingPeriodResolver flow like any other
     * entry (today's date, auto-forwarded if today's period is closed).
     * Both entries remain visible; nothing is edited in place.
     */
    public function reverse(JournalEntry $entry, string $reason, ?User $reversedBy = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $reversedBy) {
            $entry->loadMissing('lines.account', 'lines.analyticAccount', 'lines.taxCode', 'tenant');

            $reversedLines = $entry->lines->map(fn (JournalLine $l) => new LineInput(
                account: $l->account->name,
                debit: $l->credit_cents,
                credit: $l->debit_cents,
                analyticAccountCode: $l->analyticAccount?->cost_code,
                taxCode: $l->taxCode?->code,
            ))->all();

            $input = new EntryInput(
                $entry->event_type.'_reversal',
                $entry->reference_type,
                $entry->external_reference,
                ...$reversedLines,
            );

            $reversal = $this->commit(
                $entry->tenant,
                $input,
                \App\Support\BusinessTime::today(),
                $reversedBy,
                $entry->reference_id,
            );

            $reversal->update([
                'reversal_of_journal_entry_id' => $entry->id,
                'reversal_reason' => $reason,
            ]);

            return $reversal->fresh(['lines.account', 'lines.analyticAccount', 'lines.taxCode']);
        });
    }

    private function resolveAccountId(Tenant $tenant, string $name): int
    {
        $key = "{$tenant->id}:account:{$name}";
        if (isset($this->resolvedIds[$key])) {
            return $this->resolvedIds[$key];
        }

        $account = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->first();

        if (! $account) {
            throw new InvalidArgumentException(
                "Chart of Accounts has no account named '{$name}' for tenant #{$tenant->id} - "
                ."every account a posting references must be seeded (architecture §3.1's standard seed)."
            );
        }

        return $this->resolvedIds[$key] = $account->id;
    }

    private function resolveAnalyticAccountId(Tenant $tenant, string $costCode): int
    {
        $key = "{$tenant->id}:analytic:{$costCode}";
        if (isset($this->resolvedIds[$key])) {
            return $this->resolvedIds[$key];
        }

        $account = AnalyticAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('cost_code', $costCode)
            ->firstOrCreate(['tenant_id' => $tenant->id, 'cost_code' => $costCode], ['name' => $costCode]);

        return $this->resolvedIds[$key] = $account->id;
    }

    private function resolveTaxCodeId(Tenant $tenant, string $code): int
    {
        $key = "{$tenant->id}:tax:{$code}";
        if (isset($this->resolvedIds[$key])) {
            return $this->resolvedIds[$key];
        }

        $taxCode = TaxCode::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('code', $code)
            ->first();

        if (! $taxCode) {
            throw new InvalidArgumentException("Unknown TaxCode '{$code}' for tenant #{$tenant->id}.");
        }

        return $this->resolvedIds[$key] = $taxCode->id;
    }

    // =========================================================================
    // §7: GRN goods receipt (QC passed)
    // =========================================================================
    // $amount is the line value (quantity × unit cost) - quantity isn't a
    // monetary concept, so that multiplication is the caller's job
    // (GRNLine, once procurement exists), the same way postDeliveryCogs/
    // postReworkScrapWriteOff/etc. below already just take a final amount.
    public function postGrnReceipt(string $grnReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'grn_receipt', 'GRN', $grnReference,
            new LineInput('Inventory (RM)', debit: $amount),
            new LineInput('Accounts Payable', credit: $amount),
        );
    }

    // --- §7 (v19): GRN receipt for a standard-cost category ---
    public function postGrnReceiptStandardCost(
        string $grnReference,
        Money $standardValue,
        Money $actualValue,
    ): EntryInput {
        $variance = $standardValue->sub($actualValue);

        $lines = [
            new LineInput('Inventory (RM)', debit: $standardValue),
            new LineInput('Accounts Payable', credit: $actualValue),
        ];

        if ($variance->isPositive()) {
            $lines[] = new LineInput('Purchase Price Variance', credit: $variance);
        } elseif ($variance->isNegative()) {
            $lines[] = new LineInput('Purchase Price Variance', debit: $variance->abs());
        }

        return new EntryInput('grn_receipt_standard_cost', 'GRN', $grnReference, ...$lines);
    }

    /**
     * $precomputedVatAmount / $precomputedRetentionAmount (finance-billing
     * addition, both optional, default null): §3.9's InvoiceLine rounding
     * rule is explicit that "Invoice.vat_amount is the sum of the rounded
     * line-level amounts ... per-line VAT is never backed into from an
     * invoice-level total" - but grossAmount->multiplyByRate($vatRate)
     * below is exactly that single invoice-level recalculation, and can
     * differ by a cent from the real per-line sum on multi-line invoices
     * with awkward splits. Likewise retentionAmount needs to reflect
     * ContractRetentionTerms' cap enforcement (§3.9), which a flat
     * grossAmount*rate can't express once cumulative retention has hit
     * the cap. Both params exist so InvoiceService can pass its own
     * authoritative figures through instead of this method silently
     * recomputing a possibly-different one; $vatRate/$retentionPercentage
     * are still required (they drive vatOnRetention, and remain the
     * no-override behavior every existing ledger-core test call relies
     * on).
     */
    public function postInvoiceRaised(
        string $invoiceReference,
        Money $grossAmount,
        string $vatRate,
        string $retentionPercentage,
        ?Money $advanceRecovery = null,
        ?string $analyticAccountCode = null,
        ?Money $precomputedVatAmount = null,
        ?Money $precomputedRetentionAmount = null,
    ): EntryInput {
        $advanceRecovery ??= Money::zero();

        $vatAmount = $precomputedVatAmount ?? $grossAmount->multiplyByRate($vatRate);
        $retentionAmount = $precomputedRetentionAmount ?? $grossAmount->multiplyByRate($retentionPercentage);
        $vatOnRetention = $retentionAmount->multiplyByRate($vatRate);
        $netPayable = $grossAmount->add($vatAmount)->sub($retentionAmount)->sub($vatOnRetention)->sub($advanceRecovery);

        $lines = [
            new LineInput('Accounts Receivable', debit: $netPayable, analyticAccountCode: $analyticAccountCode),
        ];
        if ($advanceRecovery->isPositive()) {
            $lines[] = new LineInput('Customer Advance', debit: $advanceRecovery, analyticAccountCode: $analyticAccountCode);
        }
        $lines[] = new LineInput('Retention Receivable', debit: $retentionAmount->add($vatOnRetention), analyticAccountCode: $analyticAccountCode);
        $lines[] = new LineInput('Revenue', credit: $grossAmount, analyticAccountCode: $analyticAccountCode);
        $lines[] = new LineInput('VAT Payable', credit: $vatAmount);

        return new EntryInput('invoice_raised', 'Invoice', $invoiceReference, ...$lines);
    }

    // --- §7: Progress Claim certified, subcontract ---
    public function postProgressClaimCertified(
        string $claimReference,
        Money $amountCertified,
        string $vatRate,
        string $retentionPercentage,
        string $whtRate,
        ?string $analyticAccountCode = null,
    ): EntryInput {
        $vatAmount = $amountCertified->multiplyByRate($vatRate);
        $retentionAmount = $amountCertified->multiplyByRate($retentionPercentage);
        $vatOnRetention = $retentionAmount->multiplyByRate($vatRate);
        $whtAmount = $amountCertified->multiplyByRate($whtRate);
        $netPayable = $amountCertified->add($vatAmount)->sub($retentionAmount)->sub($vatOnRetention)->sub($whtAmount);

        return new EntryInput(
            'progress_claim_certified', 'ProgressClaim', $claimReference,
            new LineInput('Subcontract Expense', debit: $amountCertified, analyticAccountCode: $analyticAccountCode),
            new LineInput('VAT Input/Receivable', debit: $vatAmount),
            new LineInput('Accounts Payable', credit: $netPayable),
            new LineInput('Retention Payable', credit: $retentionAmount->add($vatOnRetention)),
            new LineInput('WHT Payable', credit: $whtAmount),
        );
    }

    // --- §7: Credit Note issued (client), proportional reversal ---
    public function postCreditNoteIssued(
        string $creditNoteReference,
        Money $originalGrossAmount,
        Money $originalVatAmount,
        Money $originalNetPayable,
        Money $originalRetentionPlusVat,
        string $proportion,
    ): EntryInput {
        $this->assertOriginalInvoiceFiguresAreConsistent(
            $originalGrossAmount, $originalVatAmount, $originalNetPayable, $originalRetentionPlusVat
        );

        return new EntryInput(
            'credit_note_issued', 'CreditNote', $creditNoteReference,
            new LineInput('Revenue', debit: $originalGrossAmount->multiplyByRate($proportion)),
            new LineInput('VAT Payable', debit: $originalVatAmount->multiplyByRate($proportion)),
            new LineInput('Accounts Receivable', credit: $originalNetPayable->multiplyByRate($proportion)),
            new LineInput('Retention Receivable', credit: $originalRetentionPlusVat->multiplyByRate($proportion)),
        );
    }

    // --- §7: Debit Note issued (client) or received (supplier) — mirrors Credit Note ---
    public function postDebitNoteIssued(
        string $debitNoteReference,
        Money $originalGrossAmount,
        Money $originalVatAmount,
        Money $originalNetPayable,
        Money $originalRetentionPlusVat,
        string $proportion,
    ): EntryInput {
        $this->assertOriginalInvoiceFiguresAreConsistent(
            $originalGrossAmount, $originalVatAmount, $originalNetPayable, $originalRetentionPlusVat
        );

        return new EntryInput(
            'debit_note_issued', 'DebitNote', $debitNoteReference,
            new LineInput('Accounts Receivable', debit: $originalNetPayable->multiplyByRate($proportion)),
            new LineInput('Retention Receivable', debit: $originalRetentionPlusVat->multiplyByRate($proportion)),
            new LineInput('Revenue', credit: $originalGrossAmount->multiplyByRate($proportion)),
            new LineInput('VAT Payable', credit: $originalVatAmount->multiplyByRate($proportion)),
        );
    }

    private function assertOriginalInvoiceFiguresAreConsistent(
        Money $grossAmount, Money $vatAmount, Money $netPayable, Money $retentionPlusVat
    ): void {
        $lhs = $netPayable->add($retentionPlusVat);
        $rhs = $grossAmount->add($vatAmount);

        if (! $lhs->equals($rhs)) {
            throw new InvalidArgumentException(sprintf(
                'Inconsistent original-invoice figures: net_payable (%s) + retention_plus_vat (%s) = %s, '
                .'but gross_amount (%s) + vat_amount (%s) = %s. These must be equal — pass the actual '
                .'figures from the original Invoice, not independently guessed values.',
                $netPayable, $retentionPlusVat, $lhs, $grossAmount, $vatAmount, $rhs
            ));
        }
    }

    // --- §7: Payment received from client ---
    // $allocations: one entry per PaymentAllocation line this payment
    // settles - §7's Payment-received row is explicit that one payment
    // can post against several invoices in one entry, so this sums
    // across every allocation rather than assuming a single invoice
    // (execution_plan.md's explicit ledger-core note on this method).
    //
    // @param array<int, array{reference: string, amount: Money}> $allocations
    public function postPaymentReceived(
        string $paymentReference,
        array $allocations,
        ?Money $whtWithheldByClient = null,
    ): EntryInput {
        if ($allocations === []) {
            throw new InvalidArgumentException('postPaymentReceived requires at least one allocation.');
        }

        $whtWithheldByClient ??= Money::zero();
        $netPayableAllocated = Money::sum(...array_map(fn (array $a) => $a['amount'], $allocations));
        $cashReceived = $netPayableAllocated->sub($whtWithheldByClient);

        $lines = [new LineInput('Cash/Bank', debit: $cashReceived)];
        if ($whtWithheldByClient->isPositive()) {
            $lines[] = new LineInput('WHT Receivable/Tax Credit', debit: $whtWithheldByClient);
        }
        $lines[] = new LineInput('Accounts Receivable', credit: $netPayableAllocated);

        return new EntryInput('payment_received', 'Payment', $paymentReference, ...$lines);
    }

    // --- §7: PayrollRun approved ---
    // KNOWN SIMPLIFICATION, flagged rather than hidden (same as the
    // reference file): §7 requires one JournalLine *per project* an
    // employee's Timesheets touched that period. hr-payroll's own exit
    // criterion (execution_plan.md) is where the real per-project split
    // gets enforced and tested - this method takes a single optional
    // analytic tag for the whole run until that branch exists.
    //
    // @param array<string, Money> $employeeDeductions
    // @param array<string, Money> $employerContributions no 'shif' key expected
    public function postPayrollRun(
        string $payrollRunReference,
        Money $grossPay,
        array $employeeDeductions,
        array $employerContributions,
        Money $otherDeductions,
        Money $nitaAmount,
        ?string $analyticAccountCode = null,
    ): EntryInput {
        if (array_key_exists('shif', $employerContributions)) {
            throw new InvalidArgumentException(
                'SHIF has no employer-matched portion under current Kenyan law (§7, v9) — '
                .'do not pass an employer shif contribution.'
            );
        }

        $employeeTotal = Money::sum(...array_values($employeeDeductions))->add($otherDeductions);
        $netPay = $grossPay->sub($employeeTotal);
        $employerStatutoryTotal = Money::sum(...array_values($employerContributions));

        $paye = $employeeDeductions['paye'] ?? Money::zero();
        $nssf = ($employeeDeductions['nssf'] ?? Money::zero())->add($employerContributions['nssf'] ?? Money::zero());
        $shif = $employeeDeductions['shif'] ?? Money::zero();
        $housing = ($employeeDeductions['housing'] ?? Money::zero())->add($employerContributions['housing'] ?? Money::zero());
        $helb = $employeeDeductions['helb'] ?? Money::zero();

        return new EntryInput(
            'payroll_run_approved', 'PayrollRun', $payrollRunReference,
            new LineInput('Salary/Wages Expense', debit: $grossPay, analyticAccountCode: $analyticAccountCode),
            new LineInput('Employer Statutory Expense', debit: $employerStatutoryTotal),
            new LineInput('NITA Expense', debit: $nitaAmount),
            new LineInput('PAYE Payable', credit: $paye),
            new LineInput('NSSF Payable', credit: $nssf),
            new LineInput('SHIF Payable', credit: $shif),
            new LineInput('Housing Levy Payable', credit: $housing),
            new LineInput('HELB Payable', credit: $helb),
            new LineInput('NITA Payable', credit: $nitaAmount),
            new LineInput('Other Deductions Payable', credit: $otherDeductions),
            new LineInput('Net Pay Payable', credit: $netPay),
        );
    }

    // --- §7: Statutory remittance ---
    public function postStatutoryRemittance(string $remittanceReference, string $authorityAccount, Money $amount): EntryInput
    {
        return new EntryInput(
            'statutory_remittance', 'StatutoryRemittance', $remittanceReference,
            new LineInput($authorityAccount, debit: $amount),
            new LineInput('Cash/Bank', credit: $amount),
        );
    }

    // --- §7: Asset acquired ---
    public function postAssetAcquired(string $assetReference, Money $purchaseCost): EntryInput
    {
        return new EntryInput(
            'asset_acquired', 'Asset', $assetReference,
            new LineInput('Fixed Assets at Cost', debit: $purchaseCost),
            new LineInput('Accounts Payable', credit: $purchaseCost),
        );
    }

    // --- §7: Depreciation run — never analytic-tagged (§3.12/§7, v9) ---
    public function postDepreciationRun(string $entryReference, Money $depreciationAmount): EntryInput
    {
        return new EntryInput(
            'depreciation_run', 'AssetDepreciationEntry', $entryReference,
            new LineInput('Depreciation Expense', debit: $depreciationAmount),
            new LineInput('Accumulated Depreciation', credit: $depreciationAmount),
        );
    }

    // --- §7: Internal equipment charge — the analytic-tagged mechanism ---
    public function postInternalEquipmentCharge(
        string $assignmentReference,
        Money $dailyRate,
        int $days,
        string $analyticAccountCode,
    ): EntryInput {
        $amount = $dailyRate->multiply((string) $days);

        return new EntryInput(
            'internal_equipment_charge', 'AssetAssignment', $assignmentReference,
            new LineInput('Project Equipment Cost', debit: $amount, analyticAccountCode: $analyticAccountCode),
            new LineInput('Internal Equipment Recovery', credit: $amount),
        );
    }

    // --- §7: Asset revalued upward — delta-based (v8 self-caught fix) ---
    public function postAssetRevaluedUpward(
        string $revaluationReference,
        Money $purchaseCost,
        Money $accumulatedDepreciationAtRevaluation,
        Money $newValuation,
    ): EntryInput {
        $nbvAtRevaluation = $purchaseCost->sub($accumulatedDepreciationAtRevaluation);
        $delta = $newValuation->sub($nbvAtRevaluation);

        if ($delta->isNegative()) {
            throw new InvalidArgumentException('Use postAssetRevaluedDownward() for a decrease.');
        }

        return new EntryInput(
            'asset_revalued_upward', 'AssetRevaluation', $revaluationReference,
            new LineInput('Fixed Assets at Cost', debit: $delta),
            new LineInput('Asset Revaluation Reserve', credit: $delta),
        );
    }

    // --- §7: Asset revalued downward — delta-based, mirrors upward ---
    public function postAssetRevaluedDownward(
        string $revaluationReference,
        Money $purchaseCost,
        Money $accumulatedDepreciationAtRevaluation,
        Money $newValuation,
        ?Money $existingRevaluationReserve = null,
    ): EntryInput {
        $existingRevaluationReserve ??= Money::zero();
        $nbvAtRevaluation = $purchaseCost->sub($accumulatedDepreciationAtRevaluation);
        $delta = $nbvAtRevaluation->sub($newValuation);

        if ($delta->isNegative()) {
            throw new InvalidArgumentException('Use postAssetRevaluedUpward() for an increase.');
        }

        $reserveConsumed = $delta->lessThan($existingRevaluationReserve) ? $delta : $existingRevaluationReserve;
        $plImpairment = $delta->sub($reserveConsumed);

        $lines = [];
        if ($reserveConsumed->isPositive()) {
            $lines[] = new LineInput('Asset Revaluation Reserve', debit: $reserveConsumed);
        }
        if ($plImpairment->isPositive()) {
            $lines[] = new LineInput('P&L Impairment Expense', debit: $plImpairment);
        }
        $lines[] = new LineInput('Fixed Assets at Cost', credit: $delta);

        return new EntryInput('asset_revalued_downward', 'AssetRevaluation', $revaluationReference, ...$lines);
    }

    // --- §7: Asset disposed ---
    public function postAssetDisposed(
        string $disposalReference,
        Money $purchaseCost,
        Money $accumulatedDepreciationAtDisposal,
        Money $saleProceeds,
        bool $soldOnCredit = false,
    ): EntryInput {
        $nbvAtDisposal = $purchaseCost->sub($accumulatedDepreciationAtDisposal);
        $gainLoss = $saleProceeds->sub($nbvAtDisposal);

        $lines = [
            new LineInput('Accumulated Depreciation', debit: $accumulatedDepreciationAtDisposal),
            new LineInput($soldOnCredit ? 'Accounts Receivable' : 'Cash/Bank', debit: $saleProceeds),
            new LineInput('Fixed Assets at Cost', credit: $purchaseCost),
        ];

        if ($gainLoss->isPositive()) {
            $lines[] = new LineInput('Gain on Disposal', credit: $gainLoss);
        } elseif ($gainLoss->isNegative()) {
            $lines[] = new LineInput('Loss on Disposal', debit: $gainLoss->abs());
        }

        return new EntryInput('asset_disposed', 'AssetDisposal', $disposalReference, ...$lines);
    }

    // --- §7: GRN return to supplier (QC failed) — "Reverse the above" ---
    public function postGrnReturnToSupplier(string $grnReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'grn_return_to_supplier', 'GRN', $grnReference,
            new LineInput('Accounts Payable', debit: $amount),
            new LineInput('Inventory (RM)', credit: $amount),
        );
    }

    /**
     * §7: proportional landed-cost split by value, with the classic
     * "largest remainder" fix (last line absorbs whatever rounding
     * residue remains) so the allocation always sums exactly.
     *
     * @param  array<string, Money>  $grnLineValues
     * @return array<string, Money>
     */
    public function allocateLandedCostProportionally(array $grnLineValues, Money $totalLandedCost): array
    {
        $totalLineValue = Money::sum(...array_values($grnLineValues));
        if (! $totalLineValue->isPositive()) {
            throw new InvalidArgumentException('Cannot allocate landed cost across GRN lines with zero total value.');
        }

        $keys = array_keys($grnLineValues);
        $allocated = [];
        $runningTotal = Money::zero();

        foreach ($keys as $i => $key) {
            if ($i === array_key_last($keys)) {
                $allocated[$key] = $totalLandedCost->sub($runningTotal);
            } else {
                // share = totalLandedCost * (lineValue / totalLineValue), computed via
                // bcdiv for the ratio then Money::multiplyByRate for the rounded share.
                $ratio = bcdiv((string) $grnLineValues[$key]->cents(), (string) $totalLineValue->cents(), 10);
                $share = $totalLandedCost->multiplyByRate($ratio);
                $allocated[$key] = $share;
                $runningTotal = $runningTotal->add($share);
            }
        }

        return $allocated;
    }

    public function postLandedCostAllocation(
        string $grnReference,
        Money $totalLandedCost,
        string $costType, // freight, duty, clearing, insurance — §3.4, memo only, lives on the source LandedCost record
    ): EntryInput {
        return new EntryInput(
            'landed_cost_allocation', 'LandedCost', $grnReference,
            new LineInput('Inventory (RM)', debit: $totalLandedCost),
            new LineInput('Landed Cost Payable', credit: $totalLandedCost),
        );
    }

    // --- §7: Production consumption (RM → FG), with wastage variance ---
    public function postProductionConsumption(
        string $productionOrderReference,
        Money $fgValueAtStandardCost,
        Money $rmValueActualConsumed,
    ): EntryInput {
        $variance = $fgValueAtStandardCost->sub($rmValueActualConsumed);

        $lines = [
            new LineInput('Inventory (FG)', debit: $fgValueAtStandardCost),
            new LineInput('Inventory (RM)', credit: $rmValueActualConsumed),
        ];

        if ($variance->isPositive()) {
            $lines[] = new LineInput('Wastage Variance', credit: $variance);
        } elseif ($variance->isNegative()) {
            $lines[] = new LineInput('Wastage Variance Expense', debit: $variance->abs());
        }

        return new EntryInput('production_consumption', 'ProductionOrder', $productionOrderReference, ...$lines);
    }

    // --- §7: Rework/Scrap write-off ---
    public function postReworkScrapWriteOff(string $productionOrderReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'rework_scrap_writeoff', 'ProductionOrder', $productionOrderReference,
            new LineInput('Scrap/Variance Expense', debit: $amount),
            new LineInput('Inventory (FG)', credit: $amount),
        );
    }

    // --- §7: Retention released — client side ---
    public function postRetentionReleasedClient(string $retentionReleaseReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'retention_released_client', 'RetentionRelease', $retentionReleaseReference,
            new LineInput('Cash/Bank', debit: $amount),
            new LineInput('Retention Receivable', credit: $amount),
        );
    }

    // --- §7: Retention released — subcontractor side ---
    public function postRetentionReleasedSubcontractor(string $retentionReleaseReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'retention_released_subcontractor', 'RetentionRelease', $retentionReleaseReference,
            new LineInput('Retention Payable', debit: $amount),
            new LineInput('Accounts Payable', credit: $amount),
        );
    }

    /**
     * §7: Variation Order approved — memo-only (v10), no ledger posting.
     * See the reference file's own extensive comment on why: recognizing
     * Revenue at VO-approval time would be premature/WIP-style revenue
     * recognition, which this system explicitly defers. Kept only so
     * callers have somewhere to record that a VO was approved for
     * audit-trail purposes.
     */
    public function postVariationOrderApproved(string $variationOrderReference): EntryInput
    {
        return new EntryInput('variation_order_approved', 'VariationOrder', $variationOrderReference);
    }

    // --- §7: Delivery (COGS recognition) ---
    public function postDeliveryCogs(string $deliveryReference, Money $cogsAmount): EntryInput
    {
        return new EntryInput(
            'delivery_cogs', 'Delivery', $deliveryReference,
            new LineInput('COGS', debit: $cogsAmount),
            new LineInput('Inventory (FG)', credit: $cogsAmount),
        );
    }

    // --- §7: Net pay disbursed ---
    public function postNetPayDisbursed(string $payrollRunReference, Money $netPayAmount): EntryInput
    {
        return new EntryInput(
            'net_pay_disbursed', 'PayrollRun', $payrollRunReference,
            new LineInput('Net Pay Payable', debit: $netPayAmount),
            new LineInput('Cash/Bank', credit: $netPayAmount),
        );
    }

    // --- §7: Other Deductions remitted (third-party — Sacco, union, staff loan lender) ---
    public function postOtherDeductionsRemitted(string $payrollRunReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'other_deductions_remitted', 'PayrollRun', $payrollRunReference,
            new LineInput('Other Deductions Payable', debit: $amount),
            new LineInput('Cash/Bank', credit: $amount),
        );
    }

    // --- §7 (v13): Payment made — supplier or subcontractor disbursement ---
    public function postPaymentMade(
        string $paymentReference,
        Money $apAmountSettled,
        ?Money $whtWithheldNow = null,
    ): EntryInput {
        $whtWithheldNow ??= Money::zero();
        $cashDisbursed = $apAmountSettled->sub($whtWithheldNow);

        $lines = [new LineInput('Accounts Payable', debit: $apAmountSettled)];
        $lines[] = new LineInput('Cash/Bank', credit: $cashDisbursed);
        if ($whtWithheldNow->isPositive()) {
            $lines[] = new LineInput('WHT Payable', credit: $whtWithheldNow);
        }

        return new EntryInput('payment_made', 'Payment', $paymentReference, ...$lines);
    }

    // --- §7 (v14): FX gain/loss on settlement ---
    public function postFxSettlement(
        string $paymentReference,
        Money $apPortionSettled,
        Money $cashDisbursedAtSettlementRate,
    ): EntryInput {
        $diff = $apPortionSettled->sub($cashDisbursedAtSettlementRate);

        $lines = [
            new LineInput('Accounts Payable', debit: $apPortionSettled),
        ];
        if ($diff->isPositive()) {
            $lines[] = new LineInput('FX Gain', credit: $diff);
        } elseif ($diff->isNegative()) {
            $lines[] = new LineInput('FX Loss', debit: $diff->abs());
        }
        $lines[] = new LineInput('Cash/Bank', credit: $cashDisbursedAtSettlementRate);

        return new EntryInput('fx_gain_loss', 'Payment', $paymentReference, ...$lines);
    }

    // --- §7 (v13): Opening balance (OpeningBalanceBatch posted) ---
    // @param array<string, Money> $debitBalances
    // @param array<string, Money> $creditBalances
    public function postOpeningBalance(
        string $batchReference,
        array $debitBalances,
        array $creditBalances,
    ): EntryInput {
        $totalDebits = Money::sum(...array_values($debitBalances));
        $totalCredits = Money::sum(...array_values($creditBalances));
        $plug = $totalDebits->sub($totalCredits);

        $lines = [];
        foreach ($debitBalances as $account => $amount) {
            $lines[] = new LineInput($account, debit: $amount);
        }
        foreach ($creditBalances as $account => $amount) {
            $lines[] = new LineInput($account, credit: $amount);
        }

        if ($plug->isPositive()) {
            $lines[] = new LineInput('Retained Earnings (Opening)', credit: $plug);
        } elseif ($plug->isNegative()) {
            $lines[] = new LineInput('Retained Earnings (Opening)', debit: $plug->abs());
        }

        return new EntryInput('opening_balance', 'OpeningBalanceBatch', $batchReference, ...$lines);
    }

    // --- §7 (v14): Post-acceptance supplier return ---
    public function postPostAcceptanceSupplierReturn(
        string $returnReference,
        Money $amount,
        bool $alreadyPaid = false,
    ): EntryInput {
        return new EntryInput(
            'post_qc_return_after_acceptance', 'SupplierReturn', $returnReference,
            new LineInput($alreadyPaid ? 'Accounts Receivable' : 'Accounts Payable', debit: $amount),
            new LineInput('Inventory (RM)', credit: $amount),
        );
    }

    // --- §7 (v14): Progress Claim reversed (certified, then disputed) ---
    // Exact mirror of postProgressClaimCertified — reuses reverse() so it
    // can never drift from the certification logic it's undoing. Unlike
    // every other postXxx() method, this one operates on an already-
    // persisted JournalEntry and returns a persisted JournalEntry too.
    public function postProgressClaimReversed(JournalEntry $originalCertificationEntry): JournalEntry
    {
        return $this->reverse($originalCertificationEntry, 'progress_claim_reversed');
    }

    // --- §7 (v14): Sales return ---
    public function postSalesReturn(
        string $returnReference,
        Money $originalGrossAmount,
        Money $originalVatAmount,
        Money $originalNetPayable,
        Money $originalRetentionPlusVat,
        string $proportion,
        Money $cogsValueOfReturnedGoods,
    ): EntryInput {
        return new EntryInput(
            'sales_return', 'SalesReturn', $returnReference,
            new LineInput('Revenue', debit: $originalGrossAmount->multiplyByRate($proportion)),
            new LineInput('VAT Payable', debit: $originalVatAmount->multiplyByRate($proportion)),
            new LineInput('Accounts Receivable', credit: $originalNetPayable->multiplyByRate($proportion)),
            new LineInput('Retention Receivable', credit: $originalRetentionPlusVat->multiplyByRate($proportion)),
            new LineInput('Inventory (FG)', debit: $cogsValueOfReturnedGoods),
            new LineInput('COGS', credit: $cogsValueOfReturnedGoods),
        );
    }

    // --- §7 (v14): Bad debt write-off ---
    public function postWriteOff(string $invoiceReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'write_off', 'Invoice', $invoiceReference,
            new LineInput('Bad Debt Expense', debit: $amount),
            new LineInput('Accounts Receivable', credit: $amount),
        );
    }

    // --- §7 (v14): VariationOrderReversal — memo-only, mirrors VO-approved ---
    public function postVariationOrderReversal(string $variationOrderReference): EntryInput
    {
        return new EntryInput('variation_order_reversal', 'VariationOrderReversal', $variationOrderReference);
    }

    // --- §7 (v18): WHT Receivable/Tax Credit offset against Imara's own remittance ---
    public function postWhtReceivableOffset(
        string $remittanceReference,
        Money $amount,
        string $offsetAgainst,
    ): EntryInput {
        return new EntryInput(
            'wht_receivable_offset', 'StatutoryRemittance', $remittanceReference,
            new LineInput($offsetAgainst, debit: $amount),
            new LineInput('WHT Receivable/Tax Credit', credit: $amount),
        );
    }

    // --- §7 (v18): Customer Advance refunded (unused advance) ---
    public function postCustomerAdvanceRefunded(string $paymentAllocationReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'customer_advance_refunded', 'PaymentAllocation', $paymentAllocationReference,
            new LineInput('Customer Advance', debit: $amount),
            new LineInput('Cash/Bank', credit: $amount),
        );
    }

    /**
     * finance-billing (not in the original reference-file port): §3.9's
     * PaymentAllocation.invoice_id = null *is* the Customer Advance - "It
     * posts to a dedicated Customer Advance liability account ... not
     * generically to Accounts Receivable." postPaymentReceived() only
     * ever credits Accounts Receivable, which is correct for the
     * invoice-settling portion of a receipt but wrong for the
     * unallocated/overpayment portion - crediting AR with no matching
     * Invoice would misstate a subledger that was never actually billed.
     * A genuine posting-coverage gap between the architecture's own
     * described PaymentAllocation semantics and the reference spec's
     * (invoice-only) worked examples, flagged and closed here rather
     * than silently reusing postPaymentReceived for both cases.
     */
    public function postCustomerAdvanceReceived(string $paymentReference, Money $amount): EntryInput
    {
        return new EntryInput(
            'customer_advance_received', 'Payment', $paymentReference,
            new LineInput('Cash/Bank', debit: $amount),
            new LineInput('Customer Advance', credit: $amount),
        );
    }

    // --- §7 (v18): CapitalMovement — the four directions Cash Flow's Financing category needs ---
    public function postCapitalMovement(string $movementReference, string $direction, Money $amount): EntryInput
    {
        return match ($direction) {
            'loan_received' => new EntryInput(
                'capital_movement', 'CapitalMovement', $movementReference,
                new LineInput('Cash/Bank', debit: $amount),
                new LineInput('Loan Payable', credit: $amount),
            ),
            'loan_repaid' => new EntryInput(
                'capital_movement', 'CapitalMovement', $movementReference,
                new LineInput('Loan Payable', debit: $amount),
                new LineInput('Cash/Bank', credit: $amount),
            ),
            'capital_injected' => new EntryInput(
                'capital_movement', 'CapitalMovement', $movementReference,
                new LineInput('Cash/Bank', debit: $amount),
                new LineInput('Owner Capital', credit: $amount),
            ),
            'capital_withdrawn' => new EntryInput(
                'capital_movement', 'CapitalMovement', $movementReference,
                new LineInput('Drawings', debit: $amount),
                new LineInput('Cash/Bank', credit: $amount),
            ),
            default => throw new InvalidArgumentException("Unknown CapitalMovement direction: {$direction}"),
        };
    }
}
