<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Services\Ledger\EntryInput;
use App\Services\LedgerPostingService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ledger-core: real Eloquent + PostgreSQL port of LedgerPostingServiceTest.php
 * (the reference file at the repo root). Every test below either directly
 * mirrors a reference test (same worked example, same expected figures -
 * only the mechanics changed: Money instead of float, a real commit()
 * against a real database instead of a pure in-memory value object) or is
 * new coverage this port adds (tenant scoping, the multi-invoice
 * postPaymentReceived aggregation, the reversal round trip through a real
 * persisted entry). Money's integer-cents representation means monetary
 * assertions are exact (assertSame on cents) rather than
 * assertEqualsWithDelta - there is no float drift left to tolerate, which
 * is a genuine improvement over the reference file's necessary epsilon,
 * not a looser substitute for it.
 */
class LedgerPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerPostingService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LedgerPostingService::class);
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active', 'plan_tier' => 'starter']);
    }

    private function commit(EntryInput $input): JournalEntry
    {
        return $this->service->commit($this->tenant, $input, BusinessTime::today());
    }

    private function assertBalanced(JournalEntry $entry, string $message = ''): void
    {
        self::assertTrue(
            $entry->isBalanced(),
            $message ?: sprintf(
                '%s does not balance: Dr %s vs Cr %s',
                $entry->event_type, $entry->totalDebits(), $entry->totalCredits(),
            )
        );
    }

    /** Integer cents - exact, not a tolerance check (see class docblock). */
    private function assertMoney(Money $expected, Money $actual, string $message = ''): void
    {
        self::assertSame($expected->cents(), $actual->cents(), $message ?: "expected {$expected}, got {$actual}");
    }

    private function m(float|int|string $major): Money
    {
        return Money::fromMajor($major);
    }

    // =========================================================================
    // Tenant scoping — the reference file had no DB to scope against; this
    // is new coverage the port adds (execution_plan.md's explicit
    // instruction for every ported test file).
    // =========================================================================

    public function test_journal_entries_are_tenant_scoped(): void
    {
        // execution_plan.md's actual instruction for this port is
        // query-level ("add where('tenant_id', ...) accordingly") - unlike
        // platform-foundation's exit criterion, ledger-core's doesn't call
        // for an HTTP-level proof, and there is no controller/route
        // exposing JournalEntry directly yet for one to authenticate
        // against (postings happen via other modules calling this service
        // internally, not via a client-facing endpoint).
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $entryA = $this->commit($this->service->postAssetAcquired('AST-A', $this->m(100_000)));
        $entryB = $this->service->commit($tenantB, $this->service->postAssetAcquired('AST-B', $this->m(50_000)), BusinessTime::today());

        $visibleForA = JournalEntry::where('tenant_id', $this->tenant->id)->pluck('id')->all();
        self::assertContains($entryA->id, $visibleForA);
        self::assertNotContains($entryB->id, $visibleForA, "Tenant A's scoped query must not see Tenant B's JournalEntry");

        $visibleForB = JournalEntry::where('tenant_id', $tenantB->id)->pluck('id')->all();
        self::assertContains($entryB->id, $visibleForB);
        self::assertNotContains($entryA->id, $visibleForB);
    }

    /**
     * §3.10's backdating rule, proven against a real JournalEntry rather
     * than platform-foundation's DummyRecord proxy - this is the actual
     * mechanism ledger-core is responsible for wiring up. Never rejected
     * outright: closes the current month's period, posts into it anyway,
     * and confirms the entry landed in a DIFFERENT (open) period with
     * original_intended_posting_date preserved.
     */
    public function test_journal_entry_auto_forwards_out_of_a_closed_period(): void
    {
        $today = BusinessTime::today();
        $closedPeriod = \App\Models\AccountingPeriod::where('tenant_id', $this->tenant->id)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->first();
        $closedPeriod->update(['status' => 'closed']);

        $entry = $this->commit($this->service->postAssetAcquired('AST-CLOSED', $this->m(10_000.0)));

        $this->assertBalanced($entry);
        self::assertNotSame($closedPeriod->id, $entry->accounting_period_id);
        self::assertNotNull($entry->original_intended_posting_date);
        self::assertTrue($today->isSameDay($entry->original_intended_posting_date));

        $landedPeriod = \App\Models\AccountingPeriod::find($entry->accounting_period_id);
        self::assertSame('open', $landedPeriod->status);
    }

    public function test_chart_of_accounts_are_tenant_scoped(): void
    {
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'plan_tier' => 'starter']);

        $countA = ChartOfAccount::where('tenant_id', $this->tenant->id)->count();
        $countB = ChartOfAccount::where('tenant_id', $tenantB->id)->count();

        self::assertSame($countA, $countB);
        self::assertGreaterThan(0, $countA);

        $arA = ChartOfAccount::where('tenant_id', $this->tenant->id)->where('name', 'Accounts Receivable')->first();
        $arB = ChartOfAccount::where('tenant_id', $tenantB->id)->where('name', 'Accounts Receivable')->first();
        self::assertNotSame($arA->id, $arB->id, 'each tenant must own its own Chart of Accounts row, not share one');
    }

    // =========================================================================
    // §7 worked example: gross 100, VAT 16%, retention 10%
    // =========================================================================

    public function test_invoice_raised_balances_per_doc_worked_example(): void
    {
        $entry = $this->commit($this->service->postInvoiceRaised(
            invoiceReference: 'INV-001',
            grossAmount: $this->m(100.0),
            vatRate: '0.16',
            retentionPercentage: '0.10',
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(104.4), $entry->amountFor('Accounts Receivable'));
        $this->assertMoney($this->m(11.6), $entry->amountFor('Retention Receivable'));
        $this->assertMoney($this->m(100.0), $entry->amountFor('Revenue', 'credit'));
        $this->assertMoney($this->m(16.0), $entry->amountFor('VAT Payable', 'credit'));
        $this->assertMoney($this->m(116.0), $entry->totalDebits());
    }

    public function test_invoice_raised_with_advance_recovery_still_balances(): void
    {
        $entry = $this->commit($this->service->postInvoiceRaised(
            invoiceReference: 'INV-002',
            grossAmount: $this->m(100.0),
            vatRate: '0.16',
            retentionPercentage: '0.10',
            advanceRecovery: $this->m(20.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(84.4), $entry->amountFor('Accounts Receivable'));
        $this->assertMoney($this->m(20.0), $entry->amountFor('Customer Advance'));
        $this->assertMoney($this->m(116.0), $entry->totalDebits());
    }

    // --- §7 worked example: certified 100, VAT 16%, retention 10%, WHT 3% ---
    public function test_progress_claim_certified_balances_per_doc_worked_example(): void
    {
        $entry = $this->commit($this->service->postProgressClaimCertified(
            claimReference: 'PC-001',
            amountCertified: $this->m(100.0),
            vatRate: '0.16',
            retentionPercentage: '0.10',
            whtRate: '0.03',
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(100.0), $entry->amountFor('Subcontract Expense'));
        $this->assertMoney($this->m(16.0), $entry->amountFor('VAT Input/Receivable'));
        $this->assertMoney($this->m(101.4), $entry->amountFor('Accounts Payable', 'credit'));
        $this->assertMoney($this->m(11.6), $entry->amountFor('Retention Payable', 'credit'));
        $this->assertMoney($this->m(3.0), $entry->amountFor('WHT Payable', 'credit'));
        $this->assertMoney($this->m(116.0), $entry->totalDebits());
    }

    public function test_progress_claim_vat_posts_to_input_not_payable(): void
    {
        $entry = $this->commit($this->service->postProgressClaimCertified(
            'PC-002', $this->m(100.0), '0.16', '0.10', '0.03',
        ));

        $this->assertMoney($this->m(0), $entry->amountFor('VAT Payable', 'credit'));
        self::assertTrue($entry->amountFor('VAT Input/Receivable')->isPositive());
    }

    // --- Credit note, proportional reversal (§7) ---
    public function test_credit_note_reverses_retention_proportionally(): void
    {
        $entry = $this->commit($this->service->postCreditNoteIssued(
            creditNoteReference: 'CN-001',
            originalGrossAmount: $this->m(100.0),
            originalVatAmount: $this->m(16.0),
            originalNetPayable: $this->m(104.4),
            originalRetentionPlusVat: $this->m(11.6),
            proportion: '0.5',
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(50.0), $entry->amountFor('Revenue'));
        $this->assertMoney($this->m(8.0), $entry->amountFor('VAT Payable'));
        $this->assertMoney($this->m(52.2), $entry->amountFor('Accounts Receivable', 'credit'));
        $this->assertMoney($this->m(5.8), $entry->amountFor('Retention Receivable', 'credit'));
    }

    // --- Payment received, with and without client-withheld WHT (§7) ---
    public function test_payment_received_without_client_wht(): void
    {
        $entry = $this->commit($this->service->postPaymentReceived('PAY-001', [
            ['reference' => 'INV-001', 'amount' => $this->m(104.4)],
        ]));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(104.4), $entry->amountFor('Cash/Bank'));
        $this->assertMoney($this->m(0), $entry->amountFor('WHT Receivable/Tax Credit'));
    }

    public function test_payment_received_with_client_withheld_wht(): void
    {
        $entry = $this->commit($this->service->postPaymentReceived(
            'PAY-002',
            [['reference' => 'INV-001', 'amount' => $this->m(104.4)]],
            whtWithheldByClient: $this->m(5.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(99.4), $entry->amountFor('Cash/Bank'));
        $this->assertMoney($this->m(5.0), $entry->amountFor('WHT Receivable/Tax Credit'));
        $this->assertMoney($this->m(104.4), $entry->amountFor('Accounts Receivable', 'credit'));
    }

    /**
     * New coverage this port adds: one payment settling several invoices
     * in one entry - §7's Payment-received row explicitly requires this
     * (execution_plan.md's ledger-core note), but the reference file's
     * own tests only ever exercised the single-invoice case.
     */
    public function test_payment_received_aggregates_multiple_invoice_allocations(): void
    {
        $entry = $this->commit($this->service->postPaymentReceived('PAY-003', [
            ['reference' => 'INV-101', 'amount' => $this->m(60.0)],
            ['reference' => 'INV-102', 'amount' => $this->m(44.4)],
        ]));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(104.4), $entry->amountFor('Cash/Bank'));
        $this->assertMoney($this->m(104.4), $entry->amountFor('Accounts Receivable', 'credit'));
    }

    public function test_payment_received_rejects_empty_allocations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->postPaymentReceived('PAY-BAD', []);
    }

    // --- §7/v9 worked example that caught the other_deductions bug ---
    public function test_payroll_run_balances_with_other_deductions_present(): void
    {
        $entry = $this->commit($this->service->postPayrollRun(
            payrollRunReference: 'PR-2026-01',
            grossPay: $this->m(100.0),
            employeeDeductions: [
                'paye' => $this->m(10.0), 'nssf' => $this->m(6.0), 'shif' => $this->m(2.75),
                'housing' => $this->m(1.5), 'helb' => $this->m(5.0),
            ],
            employerContributions: ['nssf' => $this->m(6.0), 'housing' => $this->m(1.5)],
            otherDeductions: $this->m(5.0),
            nitaAmount: $this->m(50.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(5.0), $entry->amountFor('Other Deductions Payable', 'credit'));
        $this->assertMoney($this->m(2.75), $entry->amountFor('SHIF Payable', 'credit'));
        $this->assertMoney($this->m(12.0), $entry->amountFor('NSSF Payable', 'credit'));
        $this->assertMoney($this->m(3.0), $entry->amountFor('Housing Levy Payable', 'credit'));
        $this->assertMoney($this->m(69.75), $entry->amountFor('Net Pay Payable', 'credit'));
    }

    public function test_payroll_run_rejects_employer_shif_contribution(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->postPayrollRun(
            'PR-BAD', $this->m(100.0),
            ['paye' => $this->m(10.0)],
            ['shif' => $this->m(2.75)],
            $this->m(0), $this->m(50.0),
        );
    }

    public function test_payroll_run_balances_across_arbitrary_values(): void
    {
        $scenarios = [
            [500.0, ['paye' => 80.0, 'nssf' => 30.0, 'shif' => 13.75, 'housing' => 7.5, 'helb' => 0.0], ['nssf' => 30.0, 'housing' => 7.5], 0.0, 50.0],
            [1200.0, ['paye' => 250.0, 'nssf' => 72.0, 'shif' => 33.0, 'housing' => 18.0, 'helb' => 15.0], ['nssf' => 72.0, 'housing' => 18.0], 40.0, 50.0],
            [80.0, ['paye' => 0.0, 'nssf' => 4.8, 'shif' => 2.2, 'housing' => 1.2, 'helb' => 0.0], ['nssf' => 4.8, 'housing' => 1.2], 10.0, 50.0],
        ];

        foreach ($scenarios as $i => [$gross, $employee, $employer, $other, $nita]) {
            $entry = $this->commit($this->service->postPayrollRun(
                "PR-PROP-{$i}", $this->m($gross),
                array_map(fn ($v) => $this->m($v), $employee),
                array_map(fn ($v) => $this->m($v), $employer),
                $this->m($other), $this->m($nita),
            ));
            $this->assertBalanced($entry, "Scenario {$i} does not balance");
        }
    }

    // --- Statutory remittance clears the payable (§7, v8 gap fix) ---
    public function test_statutory_remittance_clears_payable(): void
    {
        $entry = $this->commit($this->service->postStatutoryRemittance('SR-001', 'PAYE Payable', $this->m(10.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(10.0), $entry->amountFor('PAYE Payable'));
        $this->assertMoney($this->m(10.0), $entry->amountFor('Cash/Bank', 'credit'));
    }

    public function test_payroll_then_remittance_round_trips_payable_to_zero(): void
    {
        $payroll = $this->commit($this->service->postPayrollRun(
            'PR-002', $this->m(1000.0),
            ['paye' => $this->m(200.0), 'nssf' => $this->m(60.0), 'shif' => $this->m(27.5), 'housing' => $this->m(15.0), 'helb' => $this->m(0)],
            ['nssf' => $this->m(60.0), 'housing' => $this->m(15.0)],
            $this->m(0), $this->m(50.0),
        ));
        $payeCreated = $payroll->amountFor('PAYE Payable', 'credit');

        $remittance = $this->commit($this->service->postStatutoryRemittance('SR-002', 'PAYE Payable', $payeCreated));
        $payeCleared = $remittance->amountFor('PAYE Payable', 'debit');

        $this->assertMoney($payeCreated, $payeCleared);
    }

    // --- Asset acquisition, depreciation, internal charge (§3.12/§7) ---
    public function test_asset_acquired_balances(): void
    {
        $entry = $this->commit($this->service->postAssetAcquired('AST-001', $this->m(2_500_000.0)));
        $this->assertBalanced($entry);
    }

    public function test_depreciation_run_is_never_analytic_tagged(): void
    {
        $entry = $this->commit($this->service->postDepreciationRun('ADE-001', $this->m(41_666.67)));

        $this->assertBalanced($entry);
        foreach ($entry->lines as $line) {
            self::assertNull($line->analytic_account_id, 'Depreciation must never carry an analytic tag');
        }
    }

    public function test_internal_equipment_charge_is_the_analytic_tagged_mechanism(): void
    {
        $entry = $this->commit($this->service->postInternalEquipmentCharge(
            assignmentReference: 'AA-001', dailyRate: $this->m(45_000.0), days: 12, analyticAccountCode: 'PROJ-SITE-B',
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(540_000.0), $entry->amountFor('Project Equipment Cost'));
        self::assertSame('PROJ-SITE-B', $entry->lines[0]->analyticAccount->cost_code);
        self::assertNull($entry->lines[1]->analytic_account_id);
    }

    // --- Asset revaluation, delta-based (v8 self-caught fix) ---
    public function test_asset_revalued_upward_uses_delta_not_full_valuation(): void
    {
        $entry = $this->commit($this->service->postAssetRevaluedUpward(
            revaluationReference: 'AR-001', purchaseCost: $this->m(1_000_000.0),
            accumulatedDepreciationAtRevaluation: $this->m(200_000.0), newValuation: $this->m(1_000_000.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(200_000.0), $entry->amountFor('Fixed Assets at Cost'));
        self::assertNotSame(100_000_000, $entry->amountFor('Fixed Assets at Cost')->cents());
    }

    public function test_asset_revalued_downward_hits_pl_only_after_reserve_exhausted(): void
    {
        $entry = $this->commit($this->service->postAssetRevaluedDownward(
            revaluationReference: 'AR-002', purchaseCost: $this->m(1_000_000.0),
            accumulatedDepreciationAtRevaluation: $this->m(200_000.0), newValuation: $this->m(700_000.0),
            existingRevaluationReserve: $this->m(60_000.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(60_000.0), $entry->amountFor('Asset Revaluation Reserve'));
        $this->assertMoney($this->m(40_000.0), $entry->amountFor('P&L Impairment Expense'));
    }

    // --- Asset disposal (§7) ---
    public function test_asset_disposed_with_gain(): void
    {
        $entry = $this->commit($this->service->postAssetDisposed(
            disposalReference: 'AD-001', purchaseCost: $this->m(500_000.0),
            accumulatedDepreciationAtDisposal: $this->m(450_000.0), saleProceeds: $this->m(70_000.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(20_000.0), $entry->amountFor('Gain on Disposal', 'credit'));
        $this->assertMoney($this->m(0), $entry->amountFor('Loss on Disposal'));
    }

    public function test_asset_disposed_with_loss(): void
    {
        $entry = $this->commit($this->service->postAssetDisposed(
            disposalReference: 'AD-002', purchaseCost: $this->m(500_000.0),
            accumulatedDepreciationAtDisposal: $this->m(450_000.0), saleProceeds: $this->m(30_000.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(20_000.0), $entry->amountFor('Loss on Disposal'));
        $this->assertMoney($this->m(0), $entry->amountFor('Gain on Disposal', 'credit'));
    }

    public function test_asset_disposed_on_credit_hits_accounts_receivable(): void
    {
        $entry = $this->commit($this->service->postAssetDisposed(
            disposalReference: 'AD-003', purchaseCost: $this->m(500_000.0),
            accumulatedDepreciationAtDisposal: $this->m(450_000.0), saleProceeds: $this->m(70_000.0),
            soldOnCredit: true,
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(70_000.0), $entry->amountFor('Accounts Receivable'));
        $this->assertMoney($this->m(0), $entry->amountFor('Cash/Bank'));
        $this->assertMoney($this->m(20_000.0), $entry->amountFor('Gain on Disposal', 'credit'));
    }

    // --- Reversal policy (§3.9): never edit, always a mirrored entry ---
    public function test_reversal_produces_a_balanced_mirrored_entry(): void
    {
        $original = $this->commit($this->service->postInvoiceRaised('INV-003', $this->m(100.0), '0.16', '0.10'));
        $reversal = $this->service->reverse($original, 'client disputed the full invoice');

        $this->assertBalanced($original);
        $this->assertBalanced($reversal);
        self::assertSame($original->id, $reversal->reversal_of_journal_entry_id);

        foreach ($original->lines as $i => $originalLine) {
            $reversedLine = $reversal->lines[$i];
            self::assertSame($originalLine->account->name, $reversedLine->account->name);
            $this->assertMoney($originalLine->debit_cents, $reversedLine->credit_cents);
            $this->assertMoney($originalLine->credit_cents, $reversedLine->debit_cents);
        }
    }

    public function test_reversal_never_edits_the_original_entry(): void
    {
        // New coverage: the reversal-only policy means BOTH entries stay
        // visible and unaltered - proven here by re-fetching the original
        // from the database after reversing and confirming its own lines
        // are untouched, not just that a new entry was created.
        $original = $this->commit($this->service->postInvoiceRaised('INV-003B', $this->m(100.0), '0.16', '0.10'));
        $originalDebitsBefore = $original->totalDebits();

        $this->service->reverse($original, 'test reversal');

        $refetched = JournalEntry::find($original->id);
        $this->assertMoney($originalDebitsBefore, $refetched->totalDebits());
        self::assertNull($refetched->reversal_of_journal_entry_id, 'the ORIGINAL entry is not itself a reversal of anything');
    }

    // --- Trial-balance-nets-to-zero test (§8's stated requirement) ---
    public function test_full_period_trial_balance_nets_to_zero(): void
    {
        $entries = [
            $this->commit($this->service->postInvoiceRaised('INV-101', $this->m(500_000.0), '0.16', '0.10')),
            $this->commit($this->service->postProgressClaimCertified('PC-101', $this->m(200_000.0), '0.16', '0.10', '0.03')),
            $this->commit($this->service->postGrnReceipt('GRN-101', $this->m(35_000.0))),
            $this->commit($this->service->postAssetAcquired('AST-101', $this->m(1_800_000.0))),
            $this->commit($this->service->postDepreciationRun('ADE-101', $this->m(30_000.0))),
            $this->commit($this->service->postInternalEquipmentCharge('AA-101', $this->m(45_000.0), 20, 'PROJ-A')),
            $this->commit($this->service->postPayrollRun(
                'PR-101', $this->m(350_000.0),
                ['paye' => $this->m(70_000.0), 'nssf' => $this->m(21_000.0), 'shif' => $this->m(9_625.0), 'housing' => $this->m(5_250.0), 'helb' => $this->m(8_000.0)],
                ['nssf' => $this->m(21_000.0), 'housing' => $this->m(5_250.0)],
                $this->m(12_000.0), $this->m(50.0),
            )),
            $this->commit($this->service->postPaymentReceived('PAY-101', [['reference' => 'INV-101', 'amount' => $this->m(468_000.0)]])),
        ];

        $totalDebits = Money::sum(...array_map(fn (JournalEntry $e) => $e->totalDebits(), $entries));
        $totalCredits = Money::sum(...array_map(fn (JournalEntry $e) => $e->totalCredits(), $entries));

        self::assertSame($totalDebits->cents(), $totalCredits->cents(), 'Period trial balance does not net to zero');

        foreach ($entries as $entry) {
            $this->assertBalanced($entry, "{$entry->event_type} broke period trial balance");
        }
    }

    // =========================================================================
    // Coverage completion — the remaining §7 rows
    // =========================================================================

    public function test_grn_return_to_supplier_reverses_the_receipt(): void
    {
        $receipt = $this->commit($this->service->postGrnReceipt('GRN-201', $this->m(35_000.0)));
        $return = $this->commit($this->service->postGrnReturnToSupplier('GRN-201', $this->m(35_000.0)));

        $this->assertBalanced($receipt);
        $this->assertBalanced($return);
        $this->assertMoney($receipt->amountFor('Inventory (RM)'), $return->amountFor('Inventory (RM)', 'credit'));
    }

    public function test_landed_cost_allocates_proportionally_and_sums_exactly(): void
    {
        $lineValues = ['LINE-A' => $this->m(100.00), 'LINE-B' => $this->m(100.00), 'LINE-C' => $this->m(100.00)];
        $totalLandedCost = $this->m(100.00);

        $allocated = $this->service->allocateLandedCostProportionally($lineValues, $totalLandedCost);

        $sum = Money::sum(...array_values($allocated));
        $this->assertMoney($totalLandedCost, $sum, 'Allocated shares must sum exactly to the total landed cost');
        $this->assertMoney($this->m(33.33), $allocated['LINE-A']);
        $this->assertMoney($this->m(33.33), $allocated['LINE-B']);
        $this->assertMoney($this->m(33.34), $allocated['LINE-C']);

        $entry = $this->commit($this->service->postLandedCostAllocation('GRN-201', $totalLandedCost, 'freight'));
        $this->assertBalanced($entry);
        $this->assertMoney($this->m(100.00), $entry->amountFor('Inventory (RM)'));
    }

    public function test_landed_cost_allocation_rejects_zero_value_lines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->allocateLandedCostProportionally(['LINE-A' => Money::zero()], $this->m(50.0));
    }

    public function test_production_consumption_with_unfavorable_variance(): void
    {
        $entry = $this->commit($this->service->postProductionConsumption('PO-301', $this->m(100.0), $this->m(110.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(10.0), $entry->amountFor('Wastage Variance Expense'));
        $this->assertMoney($this->m(0), $entry->amountFor('Wastage Variance', 'credit'));
    }

    public function test_production_consumption_with_favorable_variance(): void
    {
        $entry = $this->commit($this->service->postProductionConsumption('PO-302', $this->m(100.0), $this->m(90.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(10.0), $entry->amountFor('Wastage Variance', 'credit'));
        $this->assertMoney($this->m(0), $entry->amountFor('Wastage Variance Expense'));
    }

    public function test_production_consumption_with_no_variance(): void
    {
        $entry = $this->commit($this->service->postProductionConsumption('PO-303', $this->m(100.0), $this->m(100.0)));

        $this->assertBalanced($entry);
        self::assertCount(2, $entry->lines);
    }

    public function test_rework_scrap_writeoff_balances(): void
    {
        $entry = $this->commit($this->service->postReworkScrapWriteOff('PO-304', $this->m(15_000.0)));
        $this->assertBalanced($entry);
    }

    public function test_debit_note_mirrors_credit_note_opposite_direction(): void
    {
        $creditNote = $this->commit($this->service->postCreditNoteIssued('CN-101', $this->m(100.0), $this->m(16.0), $this->m(104.4), $this->m(11.6), '0.5'));
        $debitNote = $this->commit($this->service->postDebitNoteIssued('DN-101', $this->m(100.0), $this->m(16.0), $this->m(104.4), $this->m(11.6), '0.5'));

        $this->assertBalanced($creditNote);
        $this->assertBalanced($debitNote);
        $this->assertMoney($creditNote->amountFor('Revenue'), $debitNote->amountFor('Revenue', 'credit'));
        $this->assertMoney($creditNote->amountFor('Accounts Receivable', 'credit'), $debitNote->amountFor('Accounts Receivable'));
    }

    public function test_credit_note_rejects_inconsistent_original_invoice_figures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->postCreditNoteIssued('CN-BAD', $this->m(100.0), $this->m(20.0), $this->m(104.4), $this->m(11.6), '1.0');
    }

    public function test_retention_released_client_side_balances(): void
    {
        $entry = $this->commit($this->service->postRetentionReleasedClient('RR-401', $this->m(50_000.0)));
        $this->assertBalanced($entry);
        $this->assertMoney($this->m(50_000.0), $entry->amountFor('Cash/Bank'));
    }

    public function test_retention_released_subcontractor_side_balances(): void
    {
        $entry = $this->commit($this->service->postRetentionReleasedSubcontractor('RR-402', $this->m(20_000.0)));
        $this->assertBalanced($entry);
        $this->assertMoney($this->m(20_000.0), $entry->amountFor('Retention Payable'));
    }

    public function test_variation_order_approved_posts_nothing(): void
    {
        $entry = $this->commit($this->service->postVariationOrderApproved('VO-501'));

        $this->assertBalanced($entry);
        self::assertCount(0, $entry->lines);
        $this->assertMoney($this->m(0), $entry->totalDebits());
    }

    public function test_delivery_cogs_recognition_balances(): void
    {
        $entry = $this->commit($this->service->postDeliveryCogs('DEL-601', $this->m(82_500.0)));
        $this->assertBalanced($entry);
    }

    public function test_net_pay_disbursed_clears_the_payable(): void
    {
        $entry = $this->commit($this->service->postNetPayDisbursed('PR-701', $this->m(69.75)));
        $this->assertBalanced($entry);
        $this->assertMoney($this->m(69.75), $entry->amountFor('Net Pay Payable'));
        $this->assertMoney($this->m(69.75), $entry->amountFor('Cash/Bank', 'credit'));
    }

    public function test_other_deductions_remitted_clears_the_payable(): void
    {
        $entry = $this->commit($this->service->postOtherDeductionsRemitted('PR-702', $this->m(5.0)));
        $this->assertBalanced($entry);
        $this->assertMoney($this->m(5.0), $entry->amountFor('Other Deductions Payable'));
    }

    public function test_complete_construction_cycle_nets_to_zero(): void
    {
        $entries = [
            $this->commit($this->service->postGrnReceipt('GRN-901', $this->m(100_000.0))),
            $this->commit($this->service->postLandedCostAllocation('GRN-901', $this->m(8_000.0), 'freight')),
            $this->commit($this->service->postProductionConsumption('PO-901', $this->m(95_000.0), $this->m(98_000.0))),
            $this->commit($this->service->postReworkScrapWriteOff('PO-901', $this->m(2_000.0))),
            $this->commit($this->service->postInvoiceRaised('INV-901', $this->m(300_000.0), '0.16', '0.10')),
            $this->commit($this->service->postVariationOrderApproved('VO-901')),
            $this->commit($this->service->postDeliveryCogs('DEL-901', $this->m(180_000.0))),
            $this->commit($this->service->postPaymentReceived('PAY-901', [['reference' => 'INV-901', 'amount' => $this->m(280_000.0)]])),
            $this->commit($this->service->postRetentionReleasedClient('RR-901', $this->m(30_000.0))),
            $this->commit($this->service->postProgressClaimCertified('PC-901', $this->m(100_000.0), '0.16', '0.10', '0.03')),
            $this->commit($this->service->postRetentionReleasedSubcontractor('RR-902', $this->m(10_000.0))),
            $this->commit($this->service->postNetPayDisbursed('PR-901', $this->m(150_000.0))),
            $this->commit($this->service->postOtherDeductionsRemitted('PR-901', $this->m(4_000.0))),
        ];

        foreach ($entries as $entry) {
            $this->assertBalanced($entry, "{$entry->event_type} does not balance");
        }

        $totalDebits = Money::sum(...array_map(fn (JournalEntry $e) => $e->totalDebits(), $entries));
        $totalCredits = Money::sum(...array_map(fn (JournalEntry $e) => $e->totalCredits(), $entries));
        self::assertSame($totalDebits->cents(), $totalCredits->cents());
    }

    // =========================================================================
    // v10–v14 coverage
    // =========================================================================

    public function test_payment_made_clears_ap_for_subcontractor_progress_claim(): void
    {
        $entry = $this->commit($this->service->postPaymentMade('PAY-M-001', $this->m(101.4)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(101.4), $entry->amountFor('Accounts Payable'));
        $this->assertMoney($this->m(101.4), $entry->amountFor('Cash/Bank', 'credit'));
        $this->assertMoney($this->m(0), $entry->amountFor('WHT Payable', 'credit'));
    }

    public function test_payment_made_with_fresh_wht_on_straight_supplier_payment(): void
    {
        $entry = $this->commit($this->service->postPaymentMade('PAY-M-002', $this->m(100.0), whtWithheldNow: $this->m(3.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(100.0), $entry->amountFor('Accounts Payable'));
        $this->assertMoney($this->m(97.0), $entry->amountFor('Cash/Bank', 'credit'));
        $this->assertMoney($this->m(3.0), $entry->amountFor('WHT Payable', 'credit'));
    }

    public function test_fx_settlement_gain_when_paid_less_than_booked(): void
    {
        $entry = $this->commit($this->service->postFxSettlement('PAY-FX-001', $this->m(130_000.0), $this->m(125_000.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(130_000.0), $entry->amountFor('Accounts Payable'));
        $this->assertMoney($this->m(5_000.0), $entry->amountFor('FX Gain', 'credit'));
        $this->assertMoney($this->m(0), $entry->amountFor('FX Loss'));
    }

    public function test_fx_settlement_loss_when_paid_more_than_booked(): void
    {
        $entry = $this->commit($this->service->postFxSettlement('PAY-FX-002', $this->m(130_000.0), $this->m(135_000.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(5_000.0), $entry->amountFor('FX Loss'));
        $this->assertMoney($this->m(0), $entry->amountFor('FX Gain', 'credit'));
    }

    public function test_opening_balance_plugs_to_retained_earnings_credit(): void
    {
        $entry = $this->commit($this->service->postOpeningBalance(
            'OBB-001',
            debitBalances: ['Cash/Bank' => $this->m(500_000.0), 'Accounts Receivable' => $this->m(300_000.0), 'Inventory (RM)' => $this->m(200_000.0)],
            creditBalances: ['Accounts Payable' => $this->m(250_000.0), 'Retention Payable' => $this->m(50_000.0)],
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(700_000.0), $entry->amountFor('Retained Earnings (Opening)', 'credit'));
        $this->assertMoney($this->m(0), $entry->amountFor('Retained Earnings (Opening)', 'debit'));
    }

    public function test_opening_balance_plugs_to_retained_earnings_debit_when_liabilities_exceed(): void
    {
        $entry = $this->commit($this->service->postOpeningBalance(
            'OBB-002',
            debitBalances: ['Cash/Bank' => $this->m(100_000.0)],
            creditBalances: ['Accounts Payable' => $this->m(150_000.0)],
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(50_000.0), $entry->amountFor('Retained Earnings (Opening)', 'debit'));
    }

    public function test_post_acceptance_supplier_return_unpaid(): void
    {
        $entry = $this->commit($this->service->postPostAcceptanceSupplierReturn('SR-001', $this->m(17_500.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(17_500.0), $entry->amountFor('Accounts Payable'));
        $this->assertMoney($this->m(17_500.0), $entry->amountFor('Inventory (RM)', 'credit'));
    }

    public function test_post_acceptance_supplier_return_already_paid(): void
    {
        $entry = $this->commit($this->service->postPostAcceptanceSupplierReturn('SR-002', $this->m(17_500.0), alreadyPaid: true));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(17_500.0), $entry->amountFor('Accounts Receivable'));
        $this->assertMoney($this->m(0), $entry->amountFor('Accounts Payable'));
    }

    public function test_progress_claim_reversed_exactly_mirrors_certification(): void
    {
        $certification = $this->commit($this->service->postProgressClaimCertified('PC-REV-001', $this->m(100.0), '0.16', '0.10', '0.03'));
        $reversal = $this->service->postProgressClaimReversed($certification);

        $this->assertBalanced($certification);
        $this->assertBalanced($reversal);
        $this->assertMoney($certification->amountFor('Subcontract Expense'), $reversal->amountFor('Subcontract Expense', 'credit'));
        $this->assertMoney($certification->amountFor('Accounts Payable', 'credit'), $reversal->amountFor('Accounts Payable'));
    }

    public function test_sales_return_reverses_revenue_and_restores_inventory(): void
    {
        $entry = $this->commit($this->service->postSalesReturn(
            returnReference: 'SRET-001',
            originalGrossAmount: $this->m(100.0), originalVatAmount: $this->m(16.0),
            originalNetPayable: $this->m(104.4), originalRetentionPlusVat: $this->m(11.6),
            proportion: '0.5', cogsValueOfReturnedGoods: $this->m(40.0),
        ));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(50.0), $entry->amountFor('Revenue'));
        $this->assertMoney($this->m(40.0), $entry->amountFor('Inventory (FG)'));
        $this->assertMoney($this->m(40.0), $entry->amountFor('COGS', 'credit'));
    }

    public function test_write_off_clears_uncollectable_ar(): void
    {
        $entry = $this->commit($this->service->postWriteOff('INV-BAD-001', $this->m(250_000.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(250_000.0), $entry->amountFor('Bad Debt Expense'));
        $this->assertMoney($this->m(250_000.0), $entry->amountFor('Accounts Receivable', 'credit'));
    }

    public function test_variation_order_reversal_is_memo_only(): void
    {
        $entry = $this->commit($this->service->postVariationOrderReversal('VOR-001'));

        $this->assertBalanced($entry);
        self::assertCount(0, $entry->lines);
    }

    // =========================================================================
    // v18 coverage
    // =========================================================================

    public function test_wht_receivable_offset_clears_the_credit(): void
    {
        $entry = $this->commit($this->service->postWhtReceivableOffset('REM-501', $this->m(15_000.0), 'PAYE Payable'));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(15_000.0), $entry->amountFor('PAYE Payable'));
        $this->assertMoney($this->m(15_000.0), $entry->amountFor('WHT Receivable/Tax Credit', 'credit'));
    }

    public function test_customer_advance_refunded_clears_without_touching_revenue(): void
    {
        $entry = $this->commit($this->service->postCustomerAdvanceRefunded('PA-601', $this->m(20_000.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(20_000.0), $entry->amountFor('Customer Advance'));
        $this->assertMoney($this->m(0), $entry->amountFor('Revenue'));
    }

    public function test_capital_movement_loan_received_balances(): void
    {
        $entry = $this->commit($this->service->postCapitalMovement('CM-701', 'loan_received', $this->m(500_000.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(500_000.0), $entry->amountFor('Cash/Bank'));
        $this->assertMoney($this->m(500_000.0), $entry->amountFor('Loan Payable', 'credit'));
    }

    public function test_capital_movement_all_four_directions_balance(): void
    {
        foreach (['loan_received', 'loan_repaid', 'capital_injected', 'capital_withdrawn'] as $i => $direction) {
            $entry = $this->commit($this->service->postCapitalMovement("CM-70{$i}", $direction, $this->m(100_000.0)));
            $this->assertBalanced($entry, "CapitalMovement direction '{$direction}' does not balance");
        }
    }

    public function test_capital_movement_rejects_unknown_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->postCapitalMovement('CM-999', 'dividend_paid', $this->m(1_000.0));
    }

    public function test_grn_receipt_standard_cost_unfavorable_variance(): void
    {
        $entry = $this->commit($this->service->postGrnReceiptStandardCost('GRN-SC-001', $this->m(35_000.0), $this->m(36_500.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(35_000.0), $entry->amountFor('Inventory (RM)'));
        $this->assertMoney($this->m(36_500.0), $entry->amountFor('Accounts Payable', 'credit'));
        $this->assertMoney($this->m(1_500.0), $entry->amountFor('Purchase Price Variance'));
    }

    public function test_grn_receipt_standard_cost_favorable_variance(): void
    {
        $entry = $this->commit($this->service->postGrnReceiptStandardCost('GRN-SC-002', $this->m(35_000.0), $this->m(34_000.0)));

        $this->assertBalanced($entry);
        $this->assertMoney($this->m(35_000.0), $entry->amountFor('Inventory (RM)'));
        $this->assertMoney($this->m(34_000.0), $entry->amountFor('Accounts Payable', 'credit'));
        $this->assertMoney($this->m(1_000.0), $entry->amountFor('Purchase Price Variance', 'credit'));
    }

    public function test_grn_receipt_standard_cost_no_variance(): void
    {
        $entry = $this->commit($this->service->postGrnReceiptStandardCost('GRN-SC-003', $this->m(35_000.0), $this->m(35_000.0)));

        $this->assertBalanced($entry);
        self::assertCount(2, $entry->lines);
    }

    public function test_fx_settlement_across_two_partial_payments_sums_to_full_settlement(): void
    {
        $first = $this->commit($this->service->postFxSettlement('PAY-FX-P1', $this->m(78_000.0), $this->m(75_000.0)));
        $second = $this->commit($this->service->postFxSettlement('PAY-FX-P2', $this->m(52_000.0), $this->m(54_000.0)));

        $this->assertBalanced($first);
        $this->assertBalanced($second);

        $totalApCleared = $first->amountFor('Accounts Payable')->add($second->amountFor('Accounts Payable'));
        $totalCashPaid = $first->amountFor('Cash/Bank', 'credit')->add($second->amountFor('Cash/Bank', 'credit'));
        $netGain = $first->amountFor('FX Gain', 'credit')->sub($first->amountFor('FX Loss'))
            ->add($second->amountFor('FX Gain', 'credit'))->sub($second->amountFor('FX Loss'));

        $this->assertMoney($this->m(130_000.0), $totalApCleared);
        $this->assertMoney($this->m(129_000.0), $totalCashPaid);
        $this->assertMoney($this->m(1_000.0), $netGain);
    }
}
