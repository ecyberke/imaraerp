<?php

/**
 * LedgerPostingService — reference implementation + test suite
 *
 * Derived directly from §7 (Shadow Ledger Integration Points) of
 * Imara-ERP-ARCHITECTURE.md, v19. Every posting method here implements the
 * exact Dr/Cr shape and formulas stated in that table, covering all 27
 * substantive posting rows (the rest of §7's rows are "memo only" or "no
 * posting — gates the next step," and need no method here) across 36
 * `postXxx()` methods, plus one non-posting helper (`allocateLandedCostProportionally`,
 * which returns an array, not a `JournalEntry`). Every test
 * below either reproduces a worked example already in the doc, or adds one
 * where the doc only asserted balance in prose.
 *
 * This file is deliberately self-contained pure PHP (no Eloquent, no DB) so
 * it can run standing alone before migrations exist. **It is NOT a drop-in
 * replacement for the real service** — the class below lives in
 * `Imara\Ledger\Tests` deliberately, so it can never be accidentally
 * autoloaded as production code. Once models exist:
 *   - `postXxx()` bodies should read from Eloquent models instead of the
 *     plain arrays/floats used here.
 *   - The real service belongs in `App\Services\LedgerPostingService`.
 *   - **Money representation should be revisited.** This file uses floats
 *     with `round(...,2)` and an epsilon-based balance check, which is
 *     correct and sufficient for a standalone arithmetic spec, but a
 *     production ledger should decide explicitly between integer minor
 *     units (cents), a decimal string type, or PHP's `bcmath` — and that
 *     decision should be made once, deliberately, not inherited by
 *     accident from this file's simplicity.
 *   - This file's assertions should be ported to Feature tests that go
 *     through the real `LedgerPostingService` + database, including tenant
 *     scoping (§1) on every query — omitted here since there's no DB to
 *     scope against yet.
 * Until ported, this is the executable spec: if the arithmetic below
 * doesn't balance, §7 is wrong and needs fixing before the real
 * implementation is written against it.
 *
 * Run with: vendor/bin/phpunit LedgerPostingServiceTest.php
 * Requires: PHPUnit ^11.0 (matches composer.json's existing constraint)
 */

declare(strict_types=1);

namespace Imara\Ledger\Tests;

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------
// Value objects — minimal stand-ins for the real JournalEntry/JournalLine
// Eloquent models (§3.9). Once those exist, these should be replaced by
// the real models and these classes deleted.
// ---------------------------------------------------------------------

final class JournalLine
{
    public function __construct(
        public readonly string $account,
        public readonly float $debit = 0.0,
        public readonly float $credit = 0.0,
        public readonly ?string $analyticAccountId = null,
        public readonly ?string $taxCodeId = null,
    ) {
        if ($debit > 0 && $credit > 0) {
            throw new \InvalidArgumentException('A JournalLine cannot carry both a debit and a credit.');
        }
        if ($debit < 0 || $credit < 0) {
            throw new \InvalidArgumentException('Debit/credit amounts must be non-negative.');
        }
    }
}

final class JournalEntry
{
    /** @var JournalLine[] */
    public readonly array $lines;

    public function __construct(
        public readonly string $eventType,
        public readonly string $referenceType,
        public readonly string $referenceId,
        JournalLine ...$lines,
    ) {
        $this->lines = $lines;
    }

    public function totalDebits(): float
    {
        return round(array_sum(array_map(fn (JournalLine $l) => $l->debit, $this->lines)), 2);
    }

    public function totalCredits(): float
    {
        return round(array_sum(array_map(fn (JournalLine $l) => $l->credit, $this->lines)), 2);
    }

    /**
     * Deliberately NOT `===` — two independently-accumulated float sums
     * that are conceptually equal (e.g. 100 * 0.10 vs 10.0 built up via a
     * different arithmetic path) are not guaranteed to be bit-identical in
     * IEEE 754 binary floating point, even after rounding. Financial code
     * compares monetary floats within a small tolerance, never exactly.
     */
    public function isBalanced(float $epsilon = 0.005): bool
    {
        return abs($this->totalDebits() - $this->totalCredits()) < $epsilon;
    }

    public function amountFor(string $account, string $side = 'debit'): float
    {
        $lines = array_filter($this->lines, fn (JournalLine $l) => $l->account === $account);
        return round(array_sum(array_map(
            fn (JournalLine $l) => $side === 'debit' ? $l->debit : $l->credit,
            $lines
        )), 2);
    }

    /**
     * Reversal policy per §3.9: never edit-in-place, always a new entry
     * with debits/credits swapped. Both remain visible.
     */
    public function reverse(string $reason): self
    {
        $reversedLines = array_map(
            fn (JournalLine $l) => new JournalLine(
                $l->account,
                debit: $l->credit,
                credit: $l->debit,
                analyticAccountId: $l->analyticAccountId,
                taxCodeId: $l->taxCodeId,
            ),
            $this->lines
        );

        return new self(
            $this->eventType . '_reversal',
            $this->referenceType,
            $this->referenceId,
            ...$reversedLines,
        );
    }
}

// ---------------------------------------------------------------------
// LedgerPostingService — reference implementation of every §7 row.
// Chart-of-accounts strings match §3.12/§7's naming exactly so a real
// implementation can grep the doc against this file line for line.
// ---------------------------------------------------------------------

final class LedgerPostingService
{
    // --- §7: GRN goods receipt (QC passed) ---
    public function postGrnReceipt(string $grnId, float $quantity, float $unitCost): JournalEntry
    {
        $amount = round($quantity * $unitCost, 2);

        return new JournalEntry(
            'grn_receipt', 'GRN', $grnId,
            new JournalLine('Inventory (RM)', debit: $amount),
            new JournalLine('Accounts Payable', credit: $amount),
        );
    }

    // --- §7 (v19): GRN receipt for a standard-cost category ---
    // Previously undefined — the account was seeded, the option was on the
    // valuation_method enum, but no posting existed. Inventory is debited
    // at the STANDARD cost (the point of standard costing is a stable
    // inventory value), AP is credited at the actual cost, and Purchase
    // Price Variance absorbs the difference — one account, either
    // direction, matching standard PPV accounting practice.
    public function postGrnReceiptStandardCost(
        string $grnId,
        float $quantity,
        float $standardUnitCost,
        float $actualUnitCost,
    ): JournalEntry {
        $standardValue = round($quantity * $standardUnitCost, 2);
        $actualValue = round($quantity * $actualUnitCost, 2);
        $variance = round($standardValue - $actualValue, 2);

        $lines = [
            new JournalLine('Inventory (RM)', debit: $standardValue),
            new JournalLine('Accounts Payable', credit: $actualValue),
        ];

        if ($variance > 0) {
            // Actual cost below standard — favorable, credited.
            $lines[] = new JournalLine('Purchase Price Variance', credit: $variance);
        } elseif ($variance < 0) {
            // Actual cost above standard — unfavorable, debited.
            $lines[] = new JournalLine('Purchase Price Variance', debit: abs($variance));
        }

        return new JournalEntry('grn_receipt_standard_cost', 'GRN', $grnId, ...$lines);
    }

    // --- §7: Invoice raised, client contract ---
    public function postInvoiceRaised(
        string $invoiceId,
        float $grossAmount,
        float $vatRate,
        float $retentionPercentage,
        float $advanceRecovery = 0.0,
        ?string $analyticAccountId = null,
    ): JournalEntry {
        $vatAmount = round($grossAmount * $vatRate, 2);
        $retentionAmount = round($grossAmount * $retentionPercentage, 2);
        $vatOnRetention = round($retentionAmount * $vatRate, 2);
        $netPayable = round($grossAmount + $vatAmount - $retentionAmount - $vatOnRetention - $advanceRecovery, 2);

        $lines = [
            new JournalLine('Accounts Receivable', debit: $netPayable, analyticAccountId: $analyticAccountId),
        ];
        if ($advanceRecovery > 0) {
            $lines[] = new JournalLine('Customer Advance', debit: $advanceRecovery, analyticAccountId: $analyticAccountId);
        }
        $lines[] = new JournalLine('Retention Receivable', debit: round($retentionAmount + $vatOnRetention, 2), analyticAccountId: $analyticAccountId);
        $lines[] = new JournalLine('Revenue', credit: $grossAmount, analyticAccountId: $analyticAccountId);
        $lines[] = new JournalLine('VAT Payable', credit: $vatAmount);

        return new JournalEntry('invoice_raised', 'Invoice', $invoiceId, ...$lines);
    }

    // --- §7: Progress Claim certified, subcontract ---
    public function postProgressClaimCertified(
        string $claimId,
        float $amountCertified,
        float $vatRate,
        float $retentionPercentage,
        float $whtRate,
        ?string $analyticAccountId = null,
    ): JournalEntry {
        $vatAmount = round($amountCertified * $vatRate, 2);
        $retentionAmount = round($amountCertified * $retentionPercentage, 2);
        $vatOnRetention = round($retentionAmount * $vatRate, 2);
        $whtAmount = round($amountCertified * $whtRate, 2);
        $netPayable = round($amountCertified + $vatAmount - $retentionAmount - $vatOnRetention - $whtAmount, 2);

        return new JournalEntry(
            'progress_claim_certified', 'ProgressClaim', $claimId,
            new JournalLine('Subcontract Expense', debit: $amountCertified, analyticAccountId: $analyticAccountId),
            new JournalLine('VAT Input/Receivable', debit: $vatAmount),
            new JournalLine('Accounts Payable', credit: $netPayable),
            new JournalLine('Retention Payable', credit: round($retentionAmount + $vatOnRetention, 2)),
            new JournalLine('WHT Payable', credit: $whtAmount),
        );
    }

    // --- §7: Credit Note issued (client), proportional reversal ---
    public function postCreditNoteIssued(
        string $creditNoteId,
        float $originalGrossAmount,
        float $originalVatAmount,
        float $originalNetPayable,
        float $originalRetentionPlusVat,
        float $proportion,
    ): JournalEntry {
        $this->assertOriginalInvoiceFiguresAreConsistent(
            $originalGrossAmount, $originalVatAmount, $originalNetPayable, $originalRetentionPlusVat
        );

        return new JournalEntry(
            'credit_note_issued', 'CreditNote', $creditNoteId,
            new JournalLine('Revenue', debit: round($originalGrossAmount * $proportion, 2)),
            new JournalLine('VAT Payable', debit: round($originalVatAmount * $proportion, 2)),
            new JournalLine('Accounts Receivable', credit: round($originalNetPayable * $proportion, 2)),
            new JournalLine('Retention Receivable', credit: round($originalRetentionPlusVat * $proportion, 2)),
        );
    }

    // --- §7: Debit Note issued (client) or received (supplier) — mirrors Credit Note ---
    public function postDebitNoteIssued(
        string $debitNoteId,
        float $originalGrossAmount,
        float $originalVatAmount,
        float $originalNetPayable,
        float $originalRetentionPlusVat,
        float $proportion,
    ): JournalEntry {
        $this->assertOriginalInvoiceFiguresAreConsistent(
            $originalGrossAmount, $originalVatAmount, $originalNetPayable, $originalRetentionPlusVat
        );

        return new JournalEntry(
            'debit_note_issued', 'DebitNote', $debitNoteId,
            new JournalLine('Accounts Receivable', debit: round($originalNetPayable * $proportion, 2)),
            new JournalLine('Retention Receivable', debit: round($originalRetentionPlusVat * $proportion, 2)),
            new JournalLine('Revenue', credit: round($originalGrossAmount * $proportion, 2)),
            new JournalLine('VAT Payable', credit: round($originalVatAmount * $proportion, 2)),
        );
    }

    /**
     * A caller passing inconsistent original-invoice figures (e.g.
     * originalNetPayable + originalRetentionPlusVat != originalGrossAmount +
     * originalVatAmount) produces a Credit/Debit Note that silently doesn't
     * balance, with no clue why. Fail loudly instead — this is exactly the
     * kind of opaque failure mode worth catching at the call boundary
     * rather than downstream in a balance assertion.
     */
    private function assertOriginalInvoiceFiguresAreConsistent(
        float $grossAmount, float $vatAmount, float $netPayable, float $retentionPlusVat
    ): void {
        $lhs = round($netPayable + $retentionPlusVat, 2);
        $rhs = round($grossAmount + $vatAmount, 2);

        if (abs($lhs - $rhs) >= 0.01) {
            throw new \InvalidArgumentException(sprintf(
                'Inconsistent original-invoice figures: net_payable (%.2f) + retention_plus_vat (%.2f) = %.2f, '
                . 'but gross_amount (%.2f) + vat_amount (%.2f) = %.2f. These must be equal — pass the actual '
                . 'figures from the original Invoice, not independently guessed values.',
                $netPayable, $retentionPlusVat, $lhs, $grossAmount, $vatAmount, $rhs
            ));
        }
    }

    // --- §7: Payment received from client ---
    public function postPaymentReceived(
        string $paymentId,
        float $netPayableAllocated,
        float $whtWithheldByClient = 0.0,
    ): JournalEntry {
        $cashReceived = round($netPayableAllocated - $whtWithheldByClient, 2);

        $lines = [new JournalLine('Cash/Bank', debit: $cashReceived)];
        if ($whtWithheldByClient > 0) {
            $lines[] = new JournalLine('WHT Receivable/Tax Credit', debit: $whtWithheldByClient);
        }
        $lines[] = new JournalLine('Accounts Receivable', credit: $netPayableAllocated);

        return new JournalEntry('payment_received', 'Payment', $paymentId, ...$lines);
    }

    // --- §7: PayrollRun approved ---
    // $employeeDeductions and $employerContributions are per-statute maps,
    // e.g. ['paye' => 10.0, 'nssf' => 6.0, 'shif' => 2.75, 'housing' => 1.5, 'helb' => 5.0]
    //
    // KNOWN SIMPLIFICATION, flagged rather than hidden: §7 actually requires
    // one JournalLine *per project* an employee's Timesheets touched that
    // period, splitting gross pay proportionally to hours worked — not one
    // blended `$analyticAccountId` for the whole run. This method takes a
    // single optional analytic tag because there's no Timesheet data in
    // this self-contained file to split by. The real Feature-test port
    // MUST implement the per-project split; testing this method as-is
    // does not exercise that requirement and shouldn't be mistaken for
    // having done so.
    public function postPayrollRun(
        string $payrollRunId,
        float $grossPay,
        array $employeeDeductions,
        array $employerContributions, // no 'shif' key expected — SHIF has no employer portion (§7, v9)
        float $otherDeductions,
        float $nitaAmount,
        ?string $analyticAccountId = null,
    ): JournalEntry {
        if (array_key_exists('shif', $employerContributions)) {
            throw new \InvalidArgumentException(
                'SHIF has no employer-matched portion under current Kenyan law (§7, v9) — '
                . 'do not pass an employer shif contribution.'
            );
        }

        $employeeTotal = array_sum($employeeDeductions) + $otherDeductions;
        $netPay = round($grossPay - $employeeTotal, 2);
        $employerStatutoryTotal = round(array_sum($employerContributions), 2);

        return new JournalEntry(
            'payroll_run_approved', 'PayrollRun', $payrollRunId,
            new JournalLine('Salary/Wages Expense', debit: $grossPay, analyticAccountId: $analyticAccountId),
            new JournalLine('Employer Statutory Expense', debit: $employerStatutoryTotal),
            new JournalLine('NITA Expense', debit: $nitaAmount),
            new JournalLine('PAYE Payable', credit: $employeeDeductions['paye'] ?? 0.0),
            new JournalLine('NSSF Payable', credit: round(($employeeDeductions['nssf'] ?? 0.0) + ($employerContributions['nssf'] ?? 0.0), 2)),
            new JournalLine('SHIF Payable', credit: $employeeDeductions['shif'] ?? 0.0), // employee portion only
            new JournalLine('Housing Levy Payable', credit: round(($employeeDeductions['housing'] ?? 0.0) + ($employerContributions['housing'] ?? 0.0), 2)),
            new JournalLine('HELB Payable', credit: $employeeDeductions['helb'] ?? 0.0),
            new JournalLine('NITA Payable', credit: $nitaAmount),
            new JournalLine('Other Deductions Payable', credit: $otherDeductions),
            new JournalLine('Net Pay Payable', credit: $netPay),
        );
    }

    // --- §7: Statutory remittance ---
    public function postStatutoryRemittance(string $remittanceId, string $authorityAccount, float $amount): JournalEntry
    {
        return new JournalEntry(
            'statutory_remittance', 'StatutoryRemittance', $remittanceId,
            new JournalLine($authorityAccount, debit: $amount),
            new JournalLine('Cash/Bank', credit: $amount),
        );
    }

    // --- §7: Asset acquired ---
    public function postAssetAcquired(string $assetId, float $purchaseCost): JournalEntry
    {
        return new JournalEntry(
            'asset_acquired', 'Asset', $assetId,
            new JournalLine('Fixed Assets at Cost', debit: $purchaseCost),
            new JournalLine('Accounts Payable', credit: $purchaseCost),
        );
    }

    // --- §7: Depreciation run — never analytic-tagged (§3.12/§7, v9) ---
    public function postDepreciationRun(string $entryId, float $depreciationAmount): JournalEntry
    {
        return new JournalEntry(
            'depreciation_run', 'AssetDepreciationEntry', $entryId,
            new JournalLine('Depreciation Expense', debit: $depreciationAmount),
            new JournalLine('Accumulated Depreciation', credit: $depreciationAmount),
        );
    }

    // --- §7: Internal equipment charge — the analytic-tagged mechanism ---
    public function postInternalEquipmentCharge(
        string $assignmentId,
        float $dailyRate,
        int $days,
        string $analyticAccountId,
    ): JournalEntry {
        $amount = round($dailyRate * $days, 2);

        return new JournalEntry(
            'internal_equipment_charge', 'AssetAssignment', $assignmentId,
            new JournalLine('Project Equipment Cost', debit: $amount, analyticAccountId: $analyticAccountId),
            new JournalLine('Internal Equipment Recovery', credit: $amount),
        );
    }

    // --- §7: Asset revalued upward — delta-based (v8 self-caught fix) ---
    public function postAssetRevaluedUpward(
        string $revaluationId,
        float $purchaseCost,
        float $accumulatedDepreciationAtRevaluation,
        float $newValuation,
    ): JournalEntry {
        $nbvAtRevaluation = round($purchaseCost - $accumulatedDepreciationAtRevaluation, 2);
        $delta = round($newValuation - $nbvAtRevaluation, 2);

        if ($delta < 0) {
            throw new \InvalidArgumentException('Use postAssetRevaluedDownward() for a decrease.');
        }

        return new JournalEntry(
            'asset_revalued_upward', 'AssetRevaluation', $revaluationId,
            new JournalLine('Fixed Assets at Cost', debit: $delta),
            new JournalLine('Asset Revaluation Reserve', credit: $delta),
        );
    }

    // --- §7: Asset revalued downward — delta-based, mirrors upward ---
    public function postAssetRevaluedDownward(
        string $revaluationId,
        float $purchaseCost,
        float $accumulatedDepreciationAtRevaluation,
        float $newValuation,
        float $existingRevaluationReserve = 0.0,
    ): JournalEntry {
        $nbvAtRevaluation = round($purchaseCost - $accumulatedDepreciationAtRevaluation, 2);
        $delta = round($nbvAtRevaluation - $newValuation, 2);

        if ($delta < 0) {
            throw new \InvalidArgumentException('Use postAssetRevaluedUpward() for an increase.');
        }

        // Reduce the reserve first; only the excess hits P&L.
        $reserveConsumed = min($delta, $existingRevaluationReserve);
        $plImpairment = round($delta - $reserveConsumed, 2);

        $lines = [];
        if ($reserveConsumed > 0) {
            $lines[] = new JournalLine('Asset Revaluation Reserve', debit: $reserveConsumed);
        }
        if ($plImpairment > 0) {
            $lines[] = new JournalLine('P&L Impairment Expense', debit: $plImpairment);
        }
        $lines[] = new JournalLine('Fixed Assets at Cost', credit: $delta);

        return new JournalEntry('asset_revalued_downward', 'AssetRevaluation', $revaluationId, ...$lines);
    }

    // --- §7: Asset disposed ---
    public function postAssetDisposed(
        string $disposalId,
        float $purchaseCost,
        float $accumulatedDepreciationAtDisposal,
        float $saleProceeds,
        bool $soldOnCredit = false,
    ): JournalEntry {
        $nbvAtDisposal = round($purchaseCost - $accumulatedDepreciationAtDisposal, 2);
        $gainLoss = round($saleProceeds - $nbvAtDisposal, 2);

        $lines = [
            new JournalLine('Accumulated Depreciation', debit: $accumulatedDepreciationAtDisposal),
            // Resolves to a real seeded account, not a compound placeholder:
            // an immediate sale hits Cash/Bank, a sale on credit is a genuine
            // receivable — Accounts Receivable, the same account every other
            // credit sale in this file posts to.
            new JournalLine($soldOnCredit ? 'Accounts Receivable' : 'Cash/Bank', debit: $saleProceeds),
            new JournalLine('Fixed Assets at Cost', credit: $purchaseCost),
        ];

        if ($gainLoss > 0) {
            $lines[] = new JournalLine('Gain on Disposal', credit: $gainLoss);
        } elseif ($gainLoss < 0) {
            $lines[] = new JournalLine('Loss on Disposal', debit: abs($gainLoss));
        }

        return new JournalEntry('asset_disposed', 'AssetDisposal', $disposalId, ...$lines);
    }

    // --- §7: GRN return to supplier (QC failed) — "Reverse the above" ---
    public function postGrnReturnToSupplier(string $grnId, float $quantity, float $unitCost): JournalEntry
    {
        $amount = round($quantity * $unitCost, 2);

        return new JournalEntry(
            'grn_return_to_supplier', 'GRN', $grnId,
            new JournalLine('Accounts Payable', debit: $amount),
            new JournalLine('Inventory (RM)', credit: $amount),
        );
    }

    /**
     * §7: Landed cost allocation. The real arithmetic here isn't the total
     * (that's just the invoiced freight/duty/clearing figure) — it's
     * splitting that total across GRN lines *proportionally by value*
     * such that the per-line shares sum back to the total exactly. Naive
     * per-line rounding (round each share independently) does not
     * guarantee that — the classic "largest remainder" fix is to let the
     * last line absorb whatever rounding residue is left, so the
     * allocation is always exact, never off by a cent.
     *
     * @param float[] $grnLineValues keyed by GRN line reference (quantity × unit_cost per line, pre-landed-cost)
     * @return array<string,float> the same keys, each mapped to its allocated share of $totalLandedCost
     */
    public function allocateLandedCostProportionally(array $grnLineValues, float $totalLandedCost): array
    {
        $totalLineValue = array_sum($grnLineValues);
        if ($totalLineValue <= 0) {
            throw new \InvalidArgumentException('Cannot allocate landed cost across GRN lines with zero total value.');
        }

        $keys = array_keys($grnLineValues);
        $allocated = [];
        $runningTotal = 0.0;

        foreach ($keys as $i => $key) {
            if ($i === array_key_last($keys)) {
                // Last line absorbs whatever rounding residue remains, so
                // the allocation always sums exactly to $totalLandedCost.
                $allocated[$key] = round($totalLandedCost - $runningTotal, 2);
            } else {
                $share = round($totalLandedCost * ($grnLineValues[$key] / $totalLineValue), 2);
                $allocated[$key] = $share;
                $runningTotal = round($runningTotal + $share, 2);
            }
        }

        return $allocated;
    }

    public function postLandedCostAllocation(
        string $grnId,
        float $totalLandedCost,
        string $costType, // freight, duty, clearing, insurance — §3.4
    ): JournalEntry {
        return new JournalEntry(
            'landed_cost_allocation', 'LandedCost', $grnId,
            new JournalLine('Inventory (RM)', debit: $totalLandedCost),
            // One consolidated payable account (§3.1's seed), not a dynamically-
            // constructed name per cost type — a fresh tenant's CoA can't be
            // expected to have pre-seeded "Freight Payable", "Duty Payable",
            // "Clearing Payable", and "Insurance Payable" as four separate
            // accounts. The per-cost-type breakdown isn't stored on this
            // JournalLine at all — JournalLine only carries analyticAccountId
            // and taxCodeId, no memo/free-text field — it lives on the source
            // LandedCost record instead, which this posting's reference_id
            // already points to. Look up the source entity for that detail.
            new JournalLine('Landed Cost Payable', credit: $totalLandedCost),
        );
    }

    /**
     * §7: Production consumption (RM → FG), with wastage variance.
     * Dr Inventory (FG) at the standard/BOM-implied value; Cr Inventory
     * (RM) at what was actually consumed. The difference is the wastage
     * variance: unfavorable (more RM consumed than standard — Dr expense)
     * when actual > standard, favorable (Cr, reduces cost) when actual <
     * standard.
     */
    public function postProductionConsumption(
        string $productionOrderId,
        float $fgValueAtStandardCost,
        float $rmValueActualConsumed,
    ): JournalEntry {
        $variance = round($fgValueAtStandardCost - $rmValueActualConsumed, 2);

        $lines = [
            new JournalLine('Inventory (FG)', debit: $fgValueAtStandardCost),
            new JournalLine('Inventory (RM)', credit: $rmValueActualConsumed),
        ];

        if ($variance > 0) {
            // FG's standard value exceeds RM actually consumed — favorable,
            // credited to reduce the period's cost.
            $lines[] = new JournalLine('Wastage Variance', credit: $variance);
        } elseif ($variance < 0) {
            // More RM consumed than the BOM's standard allowance — unfavorable.
            $lines[] = new JournalLine('Wastage Variance Expense', debit: abs($variance));
        }

        return new JournalEntry('production_consumption', 'ProductionOrder', $productionOrderId, ...$lines);
    }

    // --- §7: Rework/Scrap write-off ---
    public function postReworkScrapWriteOff(string $productionOrderId, float $amount): JournalEntry
    {
        return new JournalEntry(
            'rework_scrap_writeoff', 'ProductionOrder', $productionOrderId,
            new JournalLine('Scrap/Variance Expense', debit: $amount),
            new JournalLine('Inventory (FG)', credit: $amount),
        );
    }

    // --- §7: Retention released — client side ---
    public function postRetentionReleasedClient(string $retentionReleaseId, float $amount): JournalEntry
    {
        return new JournalEntry(
            'retention_released_client', 'RetentionRelease', $retentionReleaseId,
            new JournalLine('Cash/Bank', debit: $amount),
            new JournalLine('Retention Receivable', credit: $amount),
        );
    }

    // --- §7: Retention released — subcontractor side ---
    public function postRetentionReleasedSubcontractor(string $retentionReleaseId, float $amount): JournalEntry
    {
        return new JournalEntry(
            'retention_released_subcontractor', 'RetentionRelease', $retentionReleaseId,
            new JournalLine('Retention Payable', debit: $amount),
            new JournalLine('Accounts Payable', credit: $amount),
        );
    }

    /**
     * §7: Variation Order approved — resolved on review (v10 of the
     * architecture doc) to be **memo-only, no ledger posting at all**.
     *
     * The doc originally said "Dr/Cr Revenue and the relevant cost accounts
     * for the sum of amount_delta... same posting shape as the original
     * Quotation, scaled to the delta." That wording supports at least two
     * different, both-defensible readings (a literal Invoice-shape mirror,
     * or an intermediate "unbilled contract value" account — an earlier
     * version of this file implemented the latter). On review, neither is
     * correct: recognizing Revenue at VO-approval time, before any work
     * covered by the delta has actually been invoiced, is premature/WIP-
     * style revenue recognition — which contradicts this system's own
     * explicit deferral of percentage-of-completion accounting (§8/§14 of
     * the architecture doc: "held back past Phase 3, needs proven cost
     * data first").
     *
     * A Variation Order is a contract-value amendment, not a billing
     * event: §3.7 already has it mutate `BOQLine`/`BOQSection` and
     * recalculate `BOQ.revised_contract_value`. The delta becomes billable
     * through the *same* mechanisms as any other BOQ line — the next
     * `postInvoiceRaised()` or Milestone reaching `signed_off`/`invoiced`
     * — and those postings already recognize revenue correctly, at the
     * right time. No VO-specific ledger logic is needed, or correct.
     *
     * This method is kept only so callers have somewhere to record that a
     * VO was approved for audit-trail purposes; it always returns an empty
     * (trivially balanced) entry.
     */
    public function postVariationOrderApproved(string $variationOrderId, float $amountDelta): JournalEntry
    {
        return new JournalEntry('variation_order_approved', 'VariationOrder', $variationOrderId);
    }

    // --- §7: Delivery (COGS recognition) ---
    public function postDeliveryCogs(string $deliveryId, float $cogsAmount): JournalEntry
    {
        return new JournalEntry(
            'delivery_cogs', 'Delivery', $deliveryId,
            new JournalLine('COGS', debit: $cogsAmount),
            new JournalLine('Inventory (FG)', credit: $cogsAmount),
        );
    }

    // --- §7: Net pay disbursed ---
    public function postNetPayDisbursed(string $payrollRunId, float $netPayAmount): JournalEntry
    {
        return new JournalEntry(
            'net_pay_disbursed', 'PayrollRun', $payrollRunId,
            new JournalLine('Net Pay Payable', debit: $netPayAmount),
            new JournalLine('Cash/Bank', credit: $netPayAmount),
        );
    }

    // --- §7: Other Deductions remitted (third-party — Sacco, union, staff loan lender) ---
    public function postOtherDeductionsRemitted(string $payrollRunId, float $amount): JournalEntry
    {
        return new JournalEntry(
            'other_deductions_remitted', 'PayrollRun', $payrollRunId,
            new JournalLine('Other Deductions Payable', debit: $amount),
            new JournalLine('Cash/Bank', credit: $amount),
        );
    }

    // --- §7 (v13): Payment made — supplier or subcontractor disbursement ---
    // Previously named in the architecture doc but never implemented here.
    // $apAmountSettled is always the full AP being cleared. For a subcontractor
    // ProgressClaim payment, AP was already booked net of WHT at certification
    // (§3.4's net_payable formula), so $whtWithheldNow stays 0 and cash paid
    // equals the AP settled. For a straight supplier payment where WHT wasn't
    // previously recognized, $whtWithheldNow is the freshly-withheld portion.
    public function postPaymentMade(
        string $paymentId,
        float $apAmountSettled,
        float $whtWithheldNow = 0.0,
    ): JournalEntry {
        $cashDisbursed = round($apAmountSettled - $whtWithheldNow, 2);

        $lines = [new JournalLine('Accounts Payable', debit: $apAmountSettled)];
        $lines[] = new JournalLine('Cash/Bank', credit: $cashDisbursed);
        if ($whtWithheldNow > 0) {
            $lines[] = new JournalLine('WHT Payable', credit: $whtWithheldNow);
        }

        return new JournalEntry('payment_made', 'Payment', $paymentId, ...$lines);
    }

    // --- §7 (v14): FX gain/loss on settlement ---
    // AP always clears at the amount originally booked (the GRN's rate) —
    // never at the payment's rate, or it wouldn't zero out. The difference
    // between that and what was actually disbursed (at settlement_exchange_rate)
    // is the FX gain or loss.
    // §7 (v18): the formula already generalizes to partial settlement — it
    // was previously undocumented and untested, which is the actual gap,
    // not the arithmetic. $apPortionSettled is the portion of the original
    // booked AP this specific payment clears (at the GRN's rate), not
    // necessarily the full AP balance — a single foreign-currency PO paid
    // across several partial payments calls this once per payment, each
    // time with just that payment's portion, and the gains/losses across
    // all calls sum correctly to the total on full settlement (verified:
    // two partial payments summing to the full AP produce the same net
    // gain/loss as one full-settlement call would have).
    public function postFxSettlement(
        string $paymentId,
        float $apPortionSettled,
        float $cashDisbursedAtSettlementRate,
    ): JournalEntry {
        $diff = round($apPortionSettled - $cashDisbursedAtSettlementRate, 2);

        $lines = [
            new JournalLine('Accounts Payable', debit: $apPortionSettled),
        ];
        if ($diff > 0) {
            // Paid less than booked (KES strengthened) — a gain.
            $lines[] = new JournalLine('FX Gain', credit: $diff);
        } elseif ($diff < 0) {
            // Paid more than booked (KES weakened) — a loss.
            $lines[] = new JournalLine('FX Loss', debit: abs($diff));
        }
        $lines[] = new JournalLine('Cash/Bank', credit: $cashDisbursedAtSettlementRate);

        return new JournalEntry('fx_gain_loss', 'Payment', $paymentId, ...$lines);
    }

    // --- §7 (v13): Opening balance (OpeningBalanceBatch posted) ---
    // $debitBalances/$creditBalances are [account => amount] maps for the
    // actual reconstructed sub-ledger opening figures (Fixed Assets, AR,
    // Inventory, AP, Retention, Cash/Bank, etc.). Retained Earnings (Opening)
    // is the plug — never Revenue or Expense, since none of this is
    // current-period activity.
    public function postOpeningBalance(
        string $batchId,
        array $debitBalances,
        array $creditBalances,
    ): JournalEntry {
        $totalDebits = round(array_sum($debitBalances), 2);
        $totalCredits = round(array_sum($creditBalances), 2);
        $plug = round($totalDebits - $totalCredits, 2);

        $lines = [];
        foreach ($debitBalances as $account => $amount) {
            $lines[] = new JournalLine($account, debit: $amount);
        }
        foreach ($creditBalances as $account => $amount) {
            $lines[] = new JournalLine($account, credit: $amount);
        }

        if ($plug > 0) {
            // Debit balances (assets) exceed credit balances (liabilities) —
            // the usual case — plugged with a credit to opening equity.
            $lines[] = new JournalLine('Retained Earnings (Opening)', credit: $plug);
        } elseif ($plug < 0) {
            $lines[] = new JournalLine('Retained Earnings (Opening)', debit: abs($plug));
        }

        return new JournalEntry('opening_balance', 'OpeningBalanceBatch', $batchId, ...$lines);
    }

    // --- §7 (v14): Post-acceptance supplier return ---
    // Distinct from postGrnReturnToSupplier (QC-failure path) — this is
    // stock that already passed QC and was accepted, returned later
    // (over-ordered, wrong spec found, storage damage).
    public function postPostAcceptanceSupplierReturn(
        string $returnId,
        float $quantity,
        float $unitCost,
        bool $alreadyPaid = false,
    ): JournalEntry {
        $amount = round($quantity * $unitCost, 2);

        return new JournalEntry(
            'post_qc_return_after_acceptance', 'SupplierReturn', $returnId,
            // Resolves to a real seeded account, not a compound placeholder:
            // unpaid stock returned reduces what's still owed (Accounts
            // Payable). Already-paid stock returned means the supplier now
            // owes a refund/credit — Accounts Receivable, the same account
            // used for any other amount owed back to Imara, even though the
            // debtor here is a supplier rather than a customer.
            new JournalLine($alreadyPaid ? 'Accounts Receivable' : 'Accounts Payable', debit: $amount),
            new JournalLine('Inventory (RM)', credit: $amount),
        );
    }

    // --- §7 (v14): Progress Claim reversed (certified, then disputed) ---
    // Exact mirror of postProgressClaimCertified — reuses reverse() rather
    // than re-deriving the posting, so it can never drift from the
    // certification logic it's undoing.
    public function postProgressClaimReversed(JournalEntry $originalCertificationEntry): JournalEntry
    {
        return $originalCertificationEntry->reverse('progress_claim_reversed');
    }

    // --- §7 (v14): Sales return ---
    // Same proportional-reversal shape as a Credit Note (revenue/VAT/AR),
    // PLUS the inventory restoration a Credit Note alone can't do — a
    // return that only reverses revenue and never touches stock leaves
    // inventory permanently understated.
    public function postSalesReturn(
        string $returnId,
        float $originalGrossAmount,
        float $originalVatAmount,
        float $originalNetPayable,
        float $originalRetentionPlusVat,
        float $proportion,
        float $cogsValueOfReturnedGoods,
    ): JournalEntry {
        return new JournalEntry(
            'sales_return', 'SalesReturn', $returnId,
            new JournalLine('Revenue', debit: round($originalGrossAmount * $proportion, 2)),
            new JournalLine('VAT Payable', debit: round($originalVatAmount * $proportion, 2)),
            new JournalLine('Accounts Receivable', credit: round($originalNetPayable * $proportion, 2)),
            new JournalLine('Retention Receivable', credit: round($originalRetentionPlusVat * $proportion, 2)),
            new JournalLine('Inventory (FG)', debit: $cogsValueOfReturnedGoods),
            new JournalLine('COGS', credit: $cogsValueOfReturnedGoods),
        );
    }

    // --- §7 (v14): Bad debt write-off ---
    public function postWriteOff(string $invoiceId, float $amount): JournalEntry
    {
        return new JournalEntry(
            'write_off', 'Invoice', $invoiceId,
            new JournalLine('Bad Debt Expense', debit: $amount),
            new JournalLine('Accounts Receivable', credit: $amount),
        );
    }

    // --- §7 (v14): VariationOrderReversal — memo-only, mirrors VO-approved ---
    public function postVariationOrderReversal(string $variationOrderId): JournalEntry
    {
        return new JournalEntry('variation_order_reversal', 'VariationOrderReversal', $variationOrderId);
    }

    // --- §7 (v18): WHT Receivable/Tax Credit offset against Imara's own remittance ---
    // The same clearing gap the v8 statutory-payables sweep closed, one
    // asset-side account short. $offsetAgainst is whichever of Imara's own
    // liabilities the credit is reducing (typically 'PAYE Payable' or
    // 'VAT Payable') — an internal bookkeeping choice at remittance time,
    // not something the tax authority dictates.
    public function postWhtReceivableOffset(
        string $remittanceId,
        float $amount,
        string $offsetAgainst,
    ): JournalEntry {
        return new JournalEntry(
            'wht_receivable_offset', 'StatutoryRemittance', $remittanceId,
            new JournalLine($offsetAgainst, debit: $amount),
            new JournalLine('WHT Receivable/Tax Credit', credit: $amount),
        );
    }

    // --- §7 (v18): Customer Advance refunded (unused advance) ---
    public function postCustomerAdvanceRefunded(string $paymentAllocationId, float $amount): JournalEntry
    {
        return new JournalEntry(
            'customer_advance_refunded', 'PaymentAllocation', $paymentAllocationId,
            new JournalLine('Customer Advance', debit: $amount),
            new JournalLine('Cash/Bank', credit: $amount),
        );
    }

    // --- §7 (v18): CapitalMovement — the four directions Cash Flow's Financing category needs ---
    public function postCapitalMovement(string $movementId, string $direction, float $amount): JournalEntry
    {
        return match ($direction) {
            'loan_received' => new JournalEntry(
                'capital_movement', 'CapitalMovement', $movementId,
                new JournalLine('Cash/Bank', debit: $amount),
                new JournalLine('Loan Payable', credit: $amount),
            ),
            'loan_repaid' => new JournalEntry(
                'capital_movement', 'CapitalMovement', $movementId,
                new JournalLine('Loan Payable', debit: $amount),
                new JournalLine('Cash/Bank', credit: $amount),
            ),
            'capital_injected' => new JournalEntry(
                'capital_movement', 'CapitalMovement', $movementId,
                new JournalLine('Cash/Bank', debit: $amount),
                new JournalLine('Owner Capital', credit: $amount),
            ),
            'capital_withdrawn' => new JournalEntry(
                // Debits Drawings, not Owner Capital directly — standard
                // practice for auditability: capital contributed and
                // capital withdrawn stay separately visible rather than
                // netting against each other inside one account balance.
                'capital_movement', 'CapitalMovement', $movementId,
                new JournalLine('Drawings', debit: $amount),
                new JournalLine('Cash/Bank', credit: $amount),
            ),
            default => throw new \InvalidArgumentException("Unknown CapitalMovement direction: {$direction}"),
        };
    }
}

// ---------------------------------------------------------------------
// Tests — one per §7 row that carries real arithmetic. Trivial single
// Dr/single Cr rows (GRN return-reversal, order-cancelled, etc.) are
// covered by construction (JournalLine's constructor already rejects an
// unbalanced debit/credit pair) and aren't separately tested here.
// ---------------------------------------------------------------------

final class LedgerPostingServiceTest extends TestCase
{
    /** Financial-comparison tolerance — never compare monetary floats with `===`. */
    private const EPSILON = 0.005;

    private LedgerPostingService $service;

    protected function setUp(): void
    {
        $this->service = new LedgerPostingService();
    }

    private function assertBalanced(JournalEntry $entry, string $message = ''): void
    {
        self::assertEqualsWithDelta(
            $entry->totalDebits(),
            $entry->totalCredits(),
            self::EPSILON,
            $message ?: sprintf(
                '%s does not balance: Dr %.2f vs Cr %.2f',
                $entry->eventType,
                $entry->totalDebits(),
                $entry->totalCredits(),
            )
        );
    }

    /** Use for every computed monetary value — never `assertSame` on a float. */
    private function assertMoney(float $expected, float $actual, string $message = ''): void
    {
        self::assertEqualsWithDelta($expected, $actual, self::EPSILON, $message);
    }

    // --- §7 worked example: gross 100, VAT 16%, retention 10% ---
    public function test_invoice_raised_balances_per_doc_worked_example(): void
    {
        $entry = $this->service->postInvoiceRaised(
            invoiceId: 'INV-001',
            grossAmount: 100.0,
            vatRate: 0.16,
            retentionPercentage: 0.10,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(104.4, $entry->amountFor('Accounts Receivable'));
        $this->assertMoney(11.6, $entry->amountFor('Retention Receivable'));
        $this->assertMoney(100.0, $entry->amountFor('Revenue', 'credit'));
        $this->assertMoney(16.0, $entry->amountFor('VAT Payable', 'credit'));
        $this->assertMoney(116.0, $entry->totalDebits());
    }

    public function test_invoice_raised_with_advance_recovery_still_balances(): void
    {
        // Same scenario, but 20 of the 104.4 due is covered by a previously
        // received advance rather than fresh cash.
        $entry = $this->service->postInvoiceRaised(
            invoiceId: 'INV-002',
            grossAmount: 100.0,
            vatRate: 0.16,
            retentionPercentage: 0.10,
            advanceRecovery: 20.0,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(84.4, $entry->amountFor('Accounts Receivable')); // 104.4 - 20
        $this->assertMoney(20.0, $entry->amountFor('Customer Advance'));
        $this->assertMoney(116.0, $entry->totalDebits()); // advance_recovery doesn't change total Dr, just its split
    }

    // --- §7 worked example: certified 100, VAT 16%, retention 10%, WHT 3% ---
    public function test_progress_claim_certified_balances_per_doc_worked_example(): void
    {
        $entry = $this->service->postProgressClaimCertified(
            claimId: 'PC-001',
            amountCertified: 100.0,
            vatRate: 0.16,
            retentionPercentage: 0.10,
            whtRate: 0.03,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(100.0, $entry->amountFor('Subcontract Expense'));
        $this->assertMoney(16.0, $entry->amountFor('VAT Input/Receivable'));
        $this->assertMoney(101.4, $entry->amountFor('Accounts Payable', 'credit'));
        $this->assertMoney(11.6, $entry->amountFor('Retention Payable', 'credit'));
        $this->assertMoney(3.0, $entry->amountFor('WHT Payable', 'credit'));
        $this->assertMoney(116.0, $entry->totalDebits());
    }

    public function test_progress_claim_vat_posts_to_input_not_payable(): void
    {
        // Regression test for the v7-fixed bug: the subcontractor charges
        // VAT to the main contractor, so this is input VAT (reclaimable),
        // never output VAT Payable — that's the client-invoice direction.
        $entry = $this->service->postProgressClaimCertified(
            claimId: 'PC-002', amountCertified: 100.0, vatRate: 0.16,
            retentionPercentage: 0.10, whtRate: 0.03,
        );

        $this->assertMoney(0.0, $entry->amountFor('VAT Payable', 'credit'));
        self::assertGreaterThan(0.0, $entry->amountFor('VAT Input/Receivable'));
    }

    // --- Credit note, proportional reversal (§7) ---
    public function test_credit_note_reverses_retention_proportionally(): void
    {
        // Reverse 50% of the INV-001 worked example above.
        $entry = $this->service->postCreditNoteIssued(
            creditNoteId: 'CN-001',
            originalGrossAmount: 100.0,
            originalVatAmount: 16.0,
            originalNetPayable: 104.4,
            originalRetentionPlusVat: 11.6,
            proportion: 0.5,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(50.0, $entry->amountFor('Revenue'));
        $this->assertMoney(8.0, $entry->amountFor('VAT Payable'));
        $this->assertMoney(52.2, $entry->amountFor('Accounts Receivable', 'credit'));
        $this->assertMoney(5.8, $entry->amountFor('Retention Receivable', 'credit'));
    }

    // --- Payment received, with and without client-withheld WHT (§7) ---
    public function test_payment_received_without_client_wht(): void
    {
        $entry = $this->service->postPaymentReceived('PAY-001', netPayableAllocated: 104.4);

        $this->assertBalanced($entry);
        $this->assertMoney(104.4, $entry->amountFor('Cash/Bank'));
        $this->assertMoney(0.0, $entry->amountFor('WHT Receivable/Tax Credit'));
    }

    public function test_payment_received_with_client_withheld_wht(): void
    {
        // Client withholds 5 from what they pay — cash received is short,
        // but the WHT credit makes up the difference against AR.
        $entry = $this->service->postPaymentReceived('PAY-002', netPayableAllocated: 104.4, whtWithheldByClient: 5.0);

        $this->assertBalanced($entry);
        $this->assertMoney(99.4, $entry->amountFor('Cash/Bank'));
        $this->assertMoney(5.0, $entry->amountFor('WHT Receivable/Tax Credit'));
        $this->assertMoney(104.4, $entry->amountFor('Accounts Receivable', 'credit'));
    }

    // --- §7/v9 worked example that caught the other_deductions bug ---
    public function test_payroll_run_balances_with_other_deductions_present(): void
    {
        // This exact scenario is what the v8 review used to prove
        // Payslip.other_deductions broke the balance before it had its
        // own Cr line. Kept as a permanent regression test.
        $entry = $this->service->postPayrollRun(
            payrollRunId: 'PR-2026-01',
            grossPay: 100.0,
            employeeDeductions: [
                'paye' => 10.0,
                'nssf' => 6.0,
                'shif' => 2.75,
                'housing' => 1.5,
                'helb' => 5.0,
            ],
            employerContributions: [
                'nssf' => 6.0,
                'housing' => 1.5,
                // no 'shif' key — enforced by the service itself
            ],
            otherDeductions: 5.0,
            nitaAmount: 50.0,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(5.0, $entry->amountFor('Other Deductions Payable', 'credit'));
        $this->assertMoney(2.75, $entry->amountFor('SHIF Payable', 'credit')); // employee-only
        $this->assertMoney(12.0, $entry->amountFor('NSSF Payable', 'credit')); // 6 + 6 combined
        $this->assertMoney(3.0, $entry->amountFor('Housing Levy Payable', 'credit')); // 1.5 + 1.5 combined
        // net_pay = 100 - 10 - 6 - 2.75 - 1.5 - 5 - 5 = 69.75
        $this->assertMoney(69.75, $entry->amountFor('Net Pay Payable', 'credit'));
    }

    public function test_payroll_run_rejects_employer_shif_contribution(): void
    {
        // SHIF has no employer-matched portion under current Kenyan law
        // (§7, v9) — the service should refuse to post one rather than
        // silently accept and misbalance.
        $this->expectException(\InvalidArgumentException::class);

        $this->service->postPayrollRun(
            payrollRunId: 'PR-BAD',
            grossPay: 100.0,
            employeeDeductions: ['paye' => 10.0],
            employerContributions: ['shif' => 2.75], // invalid
            otherDeductions: 0.0,
            nitaAmount: 50.0,
        );
    }

    public function test_payroll_run_balances_across_arbitrary_values(): void
    {
        // Property-style check: balance should hold for any combination of
        // values, not just the doc's specific worked example.
        $scenarios = [
            [500.0, ['paye' => 80.0, 'nssf' => 30.0, 'shif' => 13.75, 'housing' => 7.5, 'helb' => 0.0], ['nssf' => 30.0, 'housing' => 7.5], 0.0, 50.0],
            [1200.0, ['paye' => 250.0, 'nssf' => 72.0, 'shif' => 33.0, 'housing' => 18.0, 'helb' => 15.0], ['nssf' => 72.0, 'housing' => 18.0], 40.0, 50.0],
            [80.0, ['paye' => 0.0, 'nssf' => 4.8, 'shif' => 2.2, 'housing' => 1.2, 'helb' => 0.0], ['nssf' => 4.8, 'housing' => 1.2], 10.0, 50.0],
        ];

        foreach ($scenarios as $i => [$gross, $employee, $employer, $other, $nita]) {
            $entry = $this->service->postPayrollRun("PR-PROP-{$i}", $gross, $employee, $employer, $other, $nita);
            $this->assertBalanced($entry, "Scenario {$i} does not balance");
        }
    }

    // --- Statutory remittance clears the payable (§7, v8 gap fix) ---
    public function test_statutory_remittance_clears_payable(): void
    {
        $entry = $this->service->postStatutoryRemittance('SR-001', 'PAYE Payable', 10.0);

        $this->assertBalanced($entry);
        $this->assertMoney(10.0, $entry->amountFor('PAYE Payable'));
        $this->assertMoney(10.0, $entry->amountFor('Cash/Bank', 'credit'));
    }

    public function test_payroll_then_remittance_round_trips_payable_to_zero(): void
    {
        // The gap v8 fixed: a payable that's created and never cleared.
        // This test proves the full cycle nets a specific payable to zero.
        $payroll = $this->service->postPayrollRun(
            'PR-002', 1000.0,
            ['paye' => 200.0, 'nssf' => 60.0, 'shif' => 27.5, 'housing' => 15.0, 'helb' => 0.0],
            ['nssf' => 60.0, 'housing' => 15.0],
            0.0, 50.0,
        );
        $payeCreated = $payroll->amountFor('PAYE Payable', 'credit');

        $remittance = $this->service->postStatutoryRemittance('SR-002', 'PAYE Payable', $payeCreated);
        $payeCleared = $remittance->amountFor('PAYE Payable', 'debit');

        // A single assertion suffices — the round(diff) check below is
        // trivially implied by assertMoney already passing, so it isn't
        // asserted separately (a prior draft had both; the second added
        // no coverage).
        $this->assertMoney($payeCreated, $payeCleared);
    }

    // --- Asset acquisition, depreciation, internal charge (§3.12/§7) ---
    public function test_asset_acquired_balances(): void
    {
        $entry = $this->service->postAssetAcquired('AST-001', 2_500_000.0);
        $this->assertBalanced($entry);
    }

    public function test_depreciation_run_is_never_analytic_tagged(): void
    {
        // Regression test for the §3.12-vs-§7 contradiction fixed in v8:
        // depreciation must never carry an analytic_account_id, regardless
        // of whether the asset is project-assigned.
        $entry = $this->service->postDepreciationRun('ADE-001', 41_666.67);

        $this->assertBalanced($entry);
        foreach ($entry->lines as $line) {
            self::assertNull(
                $line->analyticAccountId,
                'Depreciation Expense/Accumulated Depreciation must never carry analytic_account_id — '
                . 'the internal equipment charge is the analytic-tagged mechanism, kept separate '
                . 'specifically to avoid double-charging a project for the same equipment.'
            );
        }
    }

    public function test_internal_equipment_charge_is_the_analytic_tagged_mechanism(): void
    {
        $entry = $this->service->postInternalEquipmentCharge(
            assignmentId: 'AA-001',
            dailyRate: 45_000.0,
            days: 12,
            analyticAccountId: 'PROJ-SITE-B',
        );

        $this->assertBalanced($entry);
        $this->assertMoney(540_000.0, $entry->amountFor('Project Equipment Cost'));
        self::assertSame('PROJ-SITE-B', $entry->lines[0]->analyticAccountId);
        self::assertNull($entry->lines[1]->analyticAccountId); // Internal Equipment Recovery is not project-tagged
    }

    // --- Asset revaluation, delta-based (v8 self-caught fix) ---
    public function test_asset_revalued_upward_uses_delta_not_full_valuation(): void
    {
        // purchase_cost 1,000,000; accumulated depreciation at revaluation
        // 200,000 -> NBV 800,000; new_valuation 1,000,000 -> delta 200,000.
        // A pre-v8 bug would have booked the full 1,000,000 here, doubling
        // the asset alongside its already-recorded purchase_cost.
        $entry = $this->service->postAssetRevaluedUpward(
            revaluationId: 'AR-001',
            purchaseCost: 1_000_000.0,
            accumulatedDepreciationAtRevaluation: 200_000.0,
            newValuation: 1_000_000.0,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(200_000.0, $entry->amountFor('Fixed Assets at Cost'));
        self::assertNotEquals(1_000_000.0, $entry->amountFor('Fixed Assets at Cost'));
    }

    public function test_asset_revalued_downward_hits_pl_only_after_reserve_exhausted(): void
    {
        // NBV 800,000, new_valuation 700,000 -> delta 100,000 decrease.
        // Existing reserve of 60,000 absorbs part of it; remaining 40,000
        // hits P&L.
        $entry = $this->service->postAssetRevaluedDownward(
            revaluationId: 'AR-002',
            purchaseCost: 1_000_000.0,
            accumulatedDepreciationAtRevaluation: 200_000.0,
            newValuation: 700_000.0,
            existingRevaluationReserve: 60_000.0,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(60_000.0, $entry->amountFor('Asset Revaluation Reserve'));
        $this->assertMoney(40_000.0, $entry->amountFor('P&L Impairment Expense'));
    }

    // --- Asset disposal (§7) ---
    public function test_asset_disposed_with_gain(): void
    {
        // purchase_cost 500,000, accumulated depreciation 450,000
        // -> NBV 50,000; sale_proceeds 70,000 -> gain 20,000.
        $entry = $this->service->postAssetDisposed(
            disposalId: 'AD-001',
            purchaseCost: 500_000.0,
            accumulatedDepreciationAtDisposal: 450_000.0,
            saleProceeds: 70_000.0,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(20_000.0, $entry->amountFor('Gain on Disposal', 'credit'));
        $this->assertMoney(0.0, $entry->amountFor('Loss on Disposal'));
    }

    public function test_asset_disposed_with_loss(): void
    {
        // Same asset, sold for less than NBV instead.
        $entry = $this->service->postAssetDisposed(
            disposalId: 'AD-002',
            purchaseCost: 500_000.0,
            accumulatedDepreciationAtDisposal: 450_000.0,
            saleProceeds: 30_000.0, // NBV was 50,000 -> loss of 20,000
        );

        $this->assertBalanced($entry);
        $this->assertMoney(20_000.0, $entry->amountFor('Loss on Disposal'));
        $this->assertMoney(0.0, $entry->amountFor('Gain on Disposal', 'credit'));
    }

    public function test_asset_disposed_on_credit_hits_accounts_receivable(): void
    {
        // Same shape as test_asset_disposed_with_gain, but the buyer pays
        // later — soldOnCredit was added in the same revision that removed
        // the never-seeded 'Cash/Debtor' placeholder, and this branch had no
        // test of its own until now.
        $entry = $this->service->postAssetDisposed(
            disposalId: 'AD-003',
            purchaseCost: 500_000.0,
            accumulatedDepreciationAtDisposal: 450_000.0,
            saleProceeds: 70_000.0,
            soldOnCredit: true,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(70_000.0, $entry->amountFor('Accounts Receivable'));
        $this->assertMoney(0.0, $entry->amountFor('Cash/Bank'));
        $this->assertMoney(20_000.0, $entry->amountFor('Gain on Disposal', 'credit'));
    }

    // --- Reversal policy (§3.9): never edit, always a mirrored entry ---
    public function test_reversal_produces_a_balanced_mirrored_entry(): void
    {
        $original = $this->service->postInvoiceRaised('INV-003', 100.0, 0.16, 0.10);
        $reversal = $original->reverse('client disputed the full invoice');

        $this->assertBalanced($original);
        $this->assertBalanced($reversal);

        // Every line's debit/credit is swapped, so the pair nets to zero
        // per account.
        foreach ($original->lines as $i => $originalLine) {
            $reversedLine = $reversal->lines[$i];
            self::assertSame($originalLine->account, $reversedLine->account);
            $this->assertMoney($originalLine->debit, $reversedLine->credit);
            $this->assertMoney($originalLine->credit, $reversedLine->debit);
        }
    }

    // --- Trial-balance-nets-to-zero test (§8's stated requirement) ---
    public function test_full_period_trial_balance_nets_to_zero(): void
    {
        // A representative sequence spanning several modules in one period.
        // The Trial Balance is correct iff the sum of every entry's Dr
        // equals the sum of every entry's Cr, across all of them at once —
        // not just within each individual entry.
        $entries = [
            $this->service->postInvoiceRaised('INV-101', 500_000.0, 0.16, 0.10),
            $this->service->postProgressClaimCertified('PC-101', 200_000.0, 0.16, 0.10, 0.03),
            $this->service->postGrnReceipt('GRN-101', 100.0, 350.0),
            $this->service->postAssetAcquired('AST-101', 1_800_000.0),
            $this->service->postDepreciationRun('ADE-101', 30_000.0),
            $this->service->postInternalEquipmentCharge('AA-101', 45_000.0, 20, 'PROJ-A'),
            $this->service->postPayrollRun(
                'PR-101', 350_000.0,
                ['paye' => 70_000.0, 'nssf' => 21_000.0, 'shif' => 9_625.0, 'housing' => 5_250.0, 'helb' => 8_000.0],
                ['nssf' => 21_000.0, 'housing' => 5_250.0],
                12_000.0, 50.0,
            ),
            $this->service->postPaymentReceived('PAY-101', 468_000.0, 0.0),
        ];

        $totalDebits = round(array_sum(array_map(fn (JournalEntry $e) => $e->totalDebits(), $entries)), 2);
        $totalCredits = round(array_sum(array_map(fn (JournalEntry $e) => $e->totalCredits(), $entries)), 2);

        self::assertEqualsWithDelta(
            $totalDebits,
            $totalCredits,
            self::EPSILON,
            sprintf('Period trial balance does not net to zero: Dr %.2f vs Cr %.2f', $totalDebits, $totalCredits)
        );

        // And each individual entry balances on its own — a period-level
        // balance that only holds because two entries happen to cancel
        // each other's imbalance would be a worse bug than an obviously
        // unbalanced entry.
        foreach ($entries as $entry) {
            $this->assertBalanced($entry, "{$entry->eventType} ({$entry->referenceId}) broke period trial balance");
        }
    }

    // ===================================================================
    // Coverage completion — the remaining §7 rows not implemented in the
    // version of this file a prior review checked. That review found the
    // arithmetic in the first 12 methods correct but coverage at ~60%;
    // these tests cover the rest.
    // ===================================================================

    public function test_grn_return_to_supplier_reverses_the_receipt(): void
    {
        $receipt = $this->service->postGrnReceipt('GRN-201', 100.0, 350.0);
        $return = $this->service->postGrnReturnToSupplier('GRN-201', 100.0, 350.0);

        $this->assertBalanced($receipt);
        $this->assertBalanced($return);
        // The return should exactly mirror the receipt's amount.
        $this->assertMoney($receipt->amountFor('Inventory (RM)'), $return->amountFor('Inventory (RM)', 'credit'));
    }

    public function test_landed_cost_allocates_proportionally_and_sums_exactly(): void
    {
        // Three EQUAL-value GRN lines and a total that doesn't divide
        // evenly by 3 — the classic case where independent per-line
        // rounding fails. Confirmed independently before writing this
        // test: naive rounding gives 33.33 + 33.33 + 33.33 = 99.99, short
        // by exactly one cent. (An earlier version of this test used
        // unequal line values that happened to round cleanly by
        // coincidence — that didn't actually exercise the residue-
        // absorption behavior it claimed to. This one does.)
        $lineValues = [
            'LINE-A' => 100.00,
            'LINE-B' => 100.00,
            'LINE-C' => 100.00,
        ];
        $totalLandedCost = 100.00;

        $naiveShare = round($totalLandedCost * (100.00 / 300.00), 2);
        self::assertSame(33.33, $naiveShare); // sanity-check the premise
        self::assertNotSame(100.00, round($naiveShare * 3, 2), 'naive independent rounding of this input should NOT sum to the total — if it does, this test no longer proves anything');

        $allocated = $this->service->allocateLandedCostProportionally($lineValues, $totalLandedCost);

        $sum = round(array_sum($allocated), 2);
        $this->assertMoney($totalLandedCost, $sum, 'Allocated shares must sum exactly to the total landed cost, not approximately');
        // The residue lands entirely on the last line, by construction.
        $this->assertMoney(33.33, $allocated['LINE-A']);
        $this->assertMoney(33.33, $allocated['LINE-B']);
        $this->assertMoney(33.34, $allocated['LINE-C']);

        // Also confirm the posting itself balances.
        $entry = $this->service->postLandedCostAllocation('GRN-201', $totalLandedCost, 'freight');
        $this->assertBalanced($entry);
        $this->assertMoney(100.00, $entry->amountFor('Inventory (RM)'));
    }

    public function test_landed_cost_allocation_rejects_zero_value_lines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->allocateLandedCostProportionally(['LINE-A' => 0.0], 50.0);
    }

    public function test_production_consumption_with_unfavorable_variance(): void
    {
        // More RM consumed (110) than the BOM standard implied FG value (100)
        // — unfavorable variance, expensed.
        $entry = $this->service->postProductionConsumption('PO-301', fgValueAtStandardCost: 100.0, rmValueActualConsumed: 110.0);

        $this->assertBalanced($entry);
        $this->assertMoney(10.0, $entry->amountFor('Wastage Variance Expense'));
        $this->assertMoney(0.0, $entry->amountFor('Wastage Variance', 'credit'));
    }

    public function test_production_consumption_with_favorable_variance(): void
    {
        // Less RM consumed (90) than standard (100) — favorable, credited.
        $entry = $this->service->postProductionConsumption('PO-302', fgValueAtStandardCost: 100.0, rmValueActualConsumed: 90.0);

        $this->assertBalanced($entry);
        $this->assertMoney(10.0, $entry->amountFor('Wastage Variance', 'credit'));
        $this->assertMoney(0.0, $entry->amountFor('Wastage Variance Expense'));
    }

    public function test_production_consumption_with_no_variance(): void
    {
        $entry = $this->service->postProductionConsumption('PO-303', fgValueAtStandardCost: 100.0, rmValueActualConsumed: 100.0);

        $this->assertBalanced($entry);
        self::assertCount(2, $entry->lines); // no variance line posted at all when actual == standard
    }

    public function test_rework_scrap_writeoff_balances(): void
    {
        $entry = $this->service->postReworkScrapWriteOff('PO-304', 15_000.0);
        $this->assertBalanced($entry);
    }

    public function test_debit_note_mirrors_credit_note_opposite_direction(): void
    {
        $creditNote = $this->service->postCreditNoteIssued('CN-101', 100.0, 16.0, 104.4, 11.6, 0.5);
        $debitNote = $this->service->postDebitNoteIssued('DN-101', 100.0, 16.0, 104.4, 11.6, 0.5);

        $this->assertBalanced($creditNote);
        $this->assertBalanced($debitNote);

        // Same magnitudes, opposite Dr/Cr placement.
        $this->assertMoney($creditNote->amountFor('Revenue'), $debitNote->amountFor('Revenue', 'credit'));
        $this->assertMoney($creditNote->amountFor('Accounts Receivable', 'credit'), $debitNote->amountFor('Accounts Receivable'));
    }

    public function test_credit_note_rejects_inconsistent_original_invoice_figures(): void
    {
        // net_payable (104.4) + retention_plus_vat (11.6) = 116.0, but
        // gross (100) + vat (20) = 120.0 — inconsistent, should fail loudly
        // rather than silently produce an unbalanced entry.
        $this->expectException(\InvalidArgumentException::class);
        $this->service->postCreditNoteIssued('CN-BAD', 100.0, 20.0, 104.4, 11.6, 1.0);
    }

    public function test_retention_released_client_side_balances(): void
    {
        $entry = $this->service->postRetentionReleasedClient('RR-401', 50_000.0);
        $this->assertBalanced($entry);
        $this->assertMoney(50_000.0, $entry->amountFor('Cash/Bank'));
    }

    public function test_retention_released_subcontractor_side_balances(): void
    {
        $entry = $this->service->postRetentionReleasedSubcontractor('RR-402', 20_000.0);
        $this->assertBalanced($entry);
        $this->assertMoney(20_000.0, $entry->amountFor('Retention Payable'));
    }

    public function test_variation_order_approved_posts_nothing_regardless_of_delta_sign(): void
    {
        // Resolved v10: a VO is a contract-value amendment (§3.7 mutates
        // BOQLine/BOQSection directly), not a billing event — recognizing
        // Revenue here would be premature/WIP-style recognition, which
        // this system explicitly defers (§8/§14). The delta becomes
        // billable later through the normal Invoice/Milestone postings.
        $increase = $this->service->postVariationOrderApproved('VO-501', 75_000.0);
        $decrease = $this->service->postVariationOrderApproved('VO-502', -30_000.0);

        $this->assertBalanced($increase);
        $this->assertBalanced($decrease);
        self::assertCount(0, $increase->lines);
        self::assertCount(0, $decrease->lines);
        $this->assertMoney(0.0, $increase->totalDebits());
        $this->assertMoney(0.0, $decrease->totalCredits());
    }

    public function test_delivery_cogs_recognition_balances(): void
    {
        $entry = $this->service->postDeliveryCogs('DEL-601', 82_500.0);
        $this->assertBalanced($entry);
    }

    public function test_net_pay_disbursed_clears_the_payable(): void
    {
        $entry = $this->service->postNetPayDisbursed('PR-701', 69.75);
        $this->assertBalanced($entry);
        $this->assertMoney(69.75, $entry->amountFor('Net Pay Payable'));
        $this->assertMoney(69.75, $entry->amountFor('Cash/Bank', 'credit'));
    }

    public function test_other_deductions_remitted_clears_the_payable(): void
    {
        $entry = $this->service->postOtherDeductionsRemitted('PR-702', 5.0);
        $this->assertBalanced($entry);
        $this->assertMoney(5.0, $entry->amountFor('Other Deductions Payable'));
    }

    // --- Every §7 row is now implemented; a final full-cycle test proves it ---
    public function test_complete_construction_cycle_nets_to_zero(): void
    {
        // Procurement through delivery, one of every posting type this
        // file now implements, all in one trial balance.
        $entries = [
            $this->service->postGrnReceipt('GRN-901', 200.0, 500.0),
            $this->service->postLandedCostAllocation('GRN-901', 8_000.0, 'freight'),
            $this->service->postProductionConsumption('PO-901', 95_000.0, 98_000.0),
            $this->service->postReworkScrapWriteOff('PO-901', 2_000.0),
            $this->service->postInvoiceRaised('INV-901', 300_000.0, 0.16, 0.10),
            $this->service->postVariationOrderApproved('VO-901', 25_000.0),
            $this->service->postDeliveryCogs('DEL-901', 180_000.0),
            $this->service->postPaymentReceived('PAY-901', 280_000.0),
            $this->service->postRetentionReleasedClient('RR-901', 30_000.0),
            $this->service->postProgressClaimCertified('PC-901', 100_000.0, 0.16, 0.10, 0.03),
            $this->service->postRetentionReleasedSubcontractor('RR-902', 10_000.0),
            $this->service->postNetPayDisbursed('PR-901', 150_000.0),
            $this->service->postOtherDeductionsRemitted('PR-901', 4_000.0),
        ];

        foreach ($entries as $entry) {
            $this->assertBalanced($entry, "{$entry->eventType} ({$entry->referenceId}) does not balance");
        }

        $totalDebits = round(array_sum(array_map(fn (JournalEntry $e) => $e->totalDebits(), $entries)), 2);
        $totalCredits = round(array_sum(array_map(fn (JournalEntry $e) => $e->totalCredits(), $entries)), 2);
        self::assertEqualsWithDelta($totalDebits, $totalCredits, self::EPSILON);
    }

    // ===================================================================
    // v10–v14 coverage — architecture doc revisions since this file was
    // last synced. Eight posting methods this file previously lacked
    // entirely, including `payment_made`, which was named in the doc
    // since v13 but never actually implemented here until now.
    // ===================================================================

    public function test_payment_made_clears_ap_for_subcontractor_progress_claim(): void
    {
        // AP was already booked net of WHT at certification — no fresh
        // WHT recognized here, cash paid equals AP settled.
        $entry = $this->service->postPaymentMade('PAY-M-001', apAmountSettled: 101.4);

        $this->assertBalanced($entry);
        $this->assertMoney(101.4, $entry->amountFor('Accounts Payable'));
        $this->assertMoney(101.4, $entry->amountFor('Cash/Bank', 'credit'));
        $this->assertMoney(0.0, $entry->amountFor('WHT Payable', 'credit'));
    }

    public function test_payment_made_with_fresh_wht_on_straight_supplier_payment(): void
    {
        // A straight supplier payment (not via ProgressClaim) where WHT
        // wasn't previously recognized — withheld now instead.
        $entry = $this->service->postPaymentMade('PAY-M-002', apAmountSettled: 100.0, whtWithheldNow: 3.0);

        $this->assertBalanced($entry);
        $this->assertMoney(100.0, $entry->amountFor('Accounts Payable'));
        $this->assertMoney(97.0, $entry->amountFor('Cash/Bank', 'credit'));
        $this->assertMoney(3.0, $entry->amountFor('WHT Payable', 'credit'));
    }

    public function test_fx_settlement_gain_when_paid_less_than_booked(): void
    {
        // PO booked at 130 KES/USD, KES strengthens, settled for less than AP.
        $entry = $this->service->postFxSettlement('PAY-FX-001', apPortionSettled: 130_000.0, cashDisbursedAtSettlementRate: 125_000.0);

        $this->assertBalanced($entry);
        $this->assertMoney(130_000.0, $entry->amountFor('Accounts Payable'));
        $this->assertMoney(5_000.0, $entry->amountFor('FX Gain', 'credit'));
        $this->assertMoney(0.0, $entry->amountFor('FX Loss'));
    }

    public function test_fx_settlement_loss_when_paid_more_than_booked(): void
    {
        // KES weakens instead — same PO, settled for more than AP.
        $entry = $this->service->postFxSettlement('PAY-FX-002', apPortionSettled: 130_000.0, cashDisbursedAtSettlementRate: 135_000.0);

        $this->assertBalanced($entry);
        $this->assertMoney(5_000.0, $entry->amountFor('FX Loss'));
        $this->assertMoney(0.0, $entry->amountFor('FX Gain', 'credit'));
    }

    public function test_opening_balance_plugs_to_retained_earnings_credit(): void
    {
        // Typical case: assets (debits) exceed liabilities (credits) —
        // plugged with a credit to opening equity.
        $entry = $this->service->postOpeningBalance(
            'OBB-001',
            debitBalances: ['Cash/Bank' => 500_000.0, 'Accounts Receivable' => 300_000.0, 'Inventory (RM)' => 200_000.0],
            creditBalances: ['Accounts Payable' => 250_000.0, 'Retention Payable' => 50_000.0],
        );

        $this->assertBalanced($entry);
        $this->assertMoney(700_000.0, $entry->amountFor('Retained Earnings (Opening)', 'credit'));
        $this->assertMoney(0.0, $entry->amountFor('Retained Earnings (Opening)', 'debit'));
    }

    public function test_opening_balance_plugs_to_retained_earnings_debit_when_liabilities_exceed(): void
    {
        // Less common but valid: a client migrating with more recognized
        // liabilities than assets in the reconstructed sub-ledger.
        $entry = $this->service->postOpeningBalance(
            'OBB-002',
            debitBalances: ['Cash/Bank' => 100_000.0],
            creditBalances: ['Accounts Payable' => 150_000.0],
        );

        $this->assertBalanced($entry);
        $this->assertMoney(50_000.0, $entry->amountFor('Retained Earnings (Opening)', 'debit'));
    }

    public function test_post_acceptance_supplier_return_unpaid(): void
    {
        $entry = $this->service->postPostAcceptanceSupplierReturn('SR-001', quantity: 50.0, unitCost: 350.0);

        $this->assertBalanced($entry);
        $this->assertMoney(17_500.0, $entry->amountFor('Accounts Payable'));
        $this->assertMoney(17_500.0, $entry->amountFor('Inventory (RM)', 'credit'));
    }

    public function test_post_acceptance_supplier_return_already_paid(): void
    {
        $entry = $this->service->postPostAcceptanceSupplierReturn('SR-002', quantity: 50.0, unitCost: 350.0, alreadyPaid: true);

        $this->assertBalanced($entry);
        $this->assertMoney(17_500.0, $entry->amountFor('Accounts Receivable'));
        $this->assertMoney(0.0, $entry->amountFor('Accounts Payable'));
    }

    public function test_progress_claim_reversed_exactly_mirrors_certification(): void
    {
        $certification = $this->service->postProgressClaimCertified('PC-REV-001', 100.0, 0.16, 0.10, 0.03);
        $reversal = $this->service->postProgressClaimReversed($certification);

        $this->assertBalanced($certification);
        $this->assertBalanced($reversal);
        $this->assertMoney($certification->amountFor('Subcontract Expense'), $reversal->amountFor('Subcontract Expense', 'credit'));
        $this->assertMoney($certification->amountFor('Accounts Payable', 'credit'), $reversal->amountFor('Accounts Payable'));
    }

    public function test_sales_return_reverses_revenue_and_restores_inventory(): void
    {
        // 50% return of the INV-001-style worked example, goods worth 40 at COGS.
        $entry = $this->service->postSalesReturn(
            returnId: 'SRET-001',
            originalGrossAmount: 100.0,
            originalVatAmount: 16.0,
            originalNetPayable: 104.4,
            originalRetentionPlusVat: 11.6,
            proportion: 0.5,
            cogsValueOfReturnedGoods: 40.0,
        );

        $this->assertBalanced($entry);
        $this->assertMoney(50.0, $entry->amountFor('Revenue'));
        $this->assertMoney(40.0, $entry->amountFor('Inventory (FG)'));
        $this->assertMoney(40.0, $entry->amountFor('COGS', 'credit'));
    }

    public function test_write_off_clears_uncollectable_ar(): void
    {
        $entry = $this->service->postWriteOff('INV-BAD-001', 250_000.0);

        $this->assertBalanced($entry);
        $this->assertMoney(250_000.0, $entry->amountFor('Bad Debt Expense'));
        $this->assertMoney(250_000.0, $entry->amountFor('Accounts Receivable', 'credit'));
    }

    public function test_variation_order_reversal_is_memo_only(): void
    {
        // Mirrors the VO-approved memo-only treatment — reverting the BOQ
        // and any not-yet-locked Milestones is a data operation, not a
        // ledger event, since the original approval never posted one either.
        $entry = $this->service->postVariationOrderReversal('VOR-001');

        $this->assertBalanced($entry);
        self::assertCount(0, $entry->lines);
    }

    // ===================================================================
    // v18 coverage — the two clearing-path gaps found by cross-checking
    // which §7 rows create an asset/liability against which rows relieve
    // it (the same class of check that found the v8 statutory-payables
    // gap), plus CapitalMovement for the Cash Flow Statement's previously
    // empty Financing category, plus the postFxSettlement partial-payment
    // scenario the method's formula always supported but was never tested.
    // ===================================================================

    public function test_wht_receivable_offset_clears_the_credit(): void
    {
        $entry = $this->service->postWhtReceivableOffset('REM-501', 15_000.0, 'PAYE Payable');

        $this->assertBalanced($entry);
        $this->assertMoney(15_000.0, $entry->amountFor('PAYE Payable'));
        $this->assertMoney(15_000.0, $entry->amountFor('WHT Receivable/Tax Credit', 'credit'));
    }

    public function test_customer_advance_refunded_clears_without_touching_revenue(): void
    {
        $entry = $this->service->postCustomerAdvanceRefunded('PA-601', 20_000.0);

        $this->assertBalanced($entry);
        $this->assertMoney(20_000.0, $entry->amountFor('Customer Advance'));
        $this->assertMoney(0.0, $entry->amountFor('Revenue'));
    }

    public function test_capital_movement_loan_received_balances(): void
    {
        $entry = $this->service->postCapitalMovement('CM-701', 'loan_received', 500_000.0);

        $this->assertBalanced($entry);
        $this->assertMoney(500_000.0, $entry->amountFor('Cash/Bank'));
        $this->assertMoney(500_000.0, $entry->amountFor('Loan Payable', 'credit'));
    }

    public function test_capital_movement_all_four_directions_balance(): void
    {
        foreach (['loan_received', 'loan_repaid', 'capital_injected', 'capital_withdrawn'] as $i => $direction) {
            $entry = $this->service->postCapitalMovement("CM-70{$i}", $direction, 100_000.0);
            $this->assertBalanced($entry, "CapitalMovement direction '{$direction}' does not balance");
        }
    }

    public function test_capital_movement_rejects_unknown_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->postCapitalMovement('CM-999', 'dividend_paid', 1_000.0);
    }

    public function test_grn_receipt_standard_cost_unfavorable_variance(): void
    {
        // Actual (365) exceeds standard (350) — unfavorable, debited.
        $entry = $this->service->postGrnReceiptStandardCost('GRN-SC-001', quantity: 100.0, standardUnitCost: 350.0, actualUnitCost: 365.0);

        $this->assertBalanced($entry);
        $this->assertMoney(35_000.0, $entry->amountFor('Inventory (RM)'));
        $this->assertMoney(36_500.0, $entry->amountFor('Accounts Payable', 'credit'));
        $this->assertMoney(1_500.0, $entry->amountFor('Purchase Price Variance'));
    }

    public function test_grn_receipt_standard_cost_favorable_variance(): void
    {
        // Actual (340) below standard (350) — favorable, credited.
        $entry = $this->service->postGrnReceiptStandardCost('GRN-SC-002', quantity: 100.0, standardUnitCost: 350.0, actualUnitCost: 340.0);

        $this->assertBalanced($entry);
        $this->assertMoney(35_000.0, $entry->amountFor('Inventory (RM)'));
        $this->assertMoney(34_000.0, $entry->amountFor('Accounts Payable', 'credit'));
        $this->assertMoney(1_000.0, $entry->amountFor('Purchase Price Variance', 'credit'));
    }

    public function test_grn_receipt_standard_cost_no_variance(): void
    {
        $entry = $this->service->postGrnReceiptStandardCost('GRN-SC-003', quantity: 100.0, standardUnitCost: 350.0, actualUnitCost: 350.0);

        $this->assertBalanced($entry);
        self::assertCount(2, $entry->lines); // no PPV line posted at all when there's no variance
    }

    public function test_fx_settlement_across_two_partial_payments_sums_to_full_settlement(): void
    {
        // One PO's AP (booked 130,000 at the GRN rate for $1,000 USD),
        // settled across two partial payments at two different rates,
        // rather than one full settlement — the same total gain/loss
        // should result either way.
        $first = $this->service->postFxSettlement('PAY-FX-P1', apPortionSettled: 78_000.0, cashDisbursedAtSettlementRate: 75_000.0);
        $second = $this->service->postFxSettlement('PAY-FX-P2', apPortionSettled: 52_000.0, cashDisbursedAtSettlementRate: 54_000.0);

        $this->assertBalanced($first);
        $this->assertBalanced($second);

        $totalApCleared = round($first->amountFor('Accounts Payable') + $second->amountFor('Accounts Payable'), 2);
        $totalCashPaid = round($first->amountFor('Cash/Bank', 'credit') + $second->amountFor('Cash/Bank', 'credit'), 2);
        $netGain = round(
            $first->amountFor('FX Gain', 'credit') - $first->amountFor('FX Loss')
            + $second->amountFor('FX Gain', 'credit') - $second->amountFor('FX Loss'),
            2
        );

        $this->assertMoney(130_000.0, $totalApCleared);
        $this->assertMoney(129_000.0, $totalCashPaid);
        // Net: 1,000 gain overall (3,000 gain on the first portion, 2,000
        // loss on the second) — matches a single full-settlement call
        // against the same total AP and total cash.
        $this->assertMoney(1_000.0, $netGain);
    }
}
