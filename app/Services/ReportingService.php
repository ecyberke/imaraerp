<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AnalyticAccount;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PaymentAllocation;
use App\Models\ProgressClaim;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §10.1: "All are derived entirely from existing tables; none require
 * new source-of-truth data." Every method here is a read-only query
 * over JournalLine/JournalEntry (plus a handful of subledger tables for
 * the reports that need document-level detail AR/AP Aging and Party
 * Ledger ask for) - nothing here writes anything.
 *
 * Sign convention, applied uniformly rather than re-derived per report:
 * asset/expense accounts are debit-normal (display balance = debit -
 * credit); liability/equity/revenue accounts are credit-normal (display
 * balance = credit - debit). A contra account (Accumulated Depreciation,
 * Drawings, Internal Equipment Recovery) needs no special-casing here -
 * its own postings already carry the opposite natural balance, so
 * summing every account in a type uniformly nets it out correctly
 * against its parent (e.g. Fixed Assets at Cost minus Accumulated
 * Depreciation both being type=asset and summed the same way).
 */
class ReportingService
{
    private const DEBIT_NORMAL = ['asset', 'expense'];

    // =========================================================================
    // Parameter convention (§10.1): point-in-time reports default to the
    // last closed AccountingPeriod's end_date; period reports default to
    // the current open period's start/end.
    // =========================================================================

    public function defaultAsOfDate(Tenant $tenant): \Carbon\CarbonInterface
    {
        $lastClosed = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('status', 'closed')
            ->orderByDesc('end_date')->first();

        return $lastClosed?->end_date ?? BusinessTime::today();
    }

    /** @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface} */
    public function defaultPeriodRange(Tenant $tenant): array
    {
        $currentOpen = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('status', 'open')
            ->where('start_date', '<=', BusinessTime::today())
            ->orderBy('start_date')->first();

        if ($currentOpen) {
            return [$currentOpen->start_date, $currentOpen->end_date];
        }

        return [BusinessTime::today()->startOfMonth(), BusinessTime::today()];
    }

    // =========================================================================
    // Trial Balance
    // =========================================================================

    public function trialBalance(Tenant $tenant, \Carbon\CarbonInterface $asOfDate): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenant->id)
            ->where('je.posting_date', '<=', $asOfDate->toDateString())
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_type')
            ->orderBy('coa.code')
            ->selectRaw('coa.id as account_id, coa.code, coa.name, coa.account_type, COALESCE(SUM(jl.debit_cents),0) as debit_cents, COALESCE(SUM(jl.credit_cents),0) as credit_cents')
            ->get();

        $lines = $rows->map(fn ($r) => [
            'account_id' => $r->account_id,
            'code' => $r->code,
            'name' => $r->name,
            'account_type' => $r->account_type,
            'debit' => (string) Money::fromCents((int) $r->debit_cents),
            'credit' => (string) Money::fromCents((int) $r->credit_cents),
        ])->all();

        $totalDebits = Money::sum(...$rows->map(fn ($r) => Money::fromCents((int) $r->debit_cents))->all());
        $totalCredits = Money::sum(...$rows->map(fn ($r) => Money::fromCents((int) $r->credit_cents))->all());

        return [
            'as_of_date' => $asOfDate->toDateString(),
            'lines' => $lines,
            'total_debits' => (string) $totalDebits,
            'total_credits' => (string) $totalCredits,
            'balanced' => $totalDebits->equals($totalCredits),
        ];
    }

    // =========================================================================
    // AR / AP Aging
    // =========================================================================

    /**
     * §10.1: "Invoice.net_payable less PaymentAllocation sums, bucketed
     * by days overdue from posting_date."
     */
    public function arAging(Tenant $tenant, \Carbon\CarbonInterface $asOfDate): array
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['raised', 'partially_paid'])
            ->with('allocations')
            ->get();

        $lines = [];
        $buckets = ['current' => Money::zero(), 'days_1_30' => Money::zero(), 'days_31_60' => Money::zero(), 'days_61_90' => Money::zero(), 'days_90_plus' => Money::zero()];

        foreach ($invoices as $invoice) {
            $allocated = Money::sum(...$invoice->allocations->where('status', 'allocated')->map(fn (PaymentAllocation $a) => $a->amount_allocated)->all());
            $outstanding = $invoice->net_payable->sub($allocated);

            if (! $outstanding->isPositive()) {
                continue;
            }

            $daysOverdue = (int) $invoice->posting_date->diffInDays($asOfDate, false);
            $bucket = $this->agingBucket($daysOverdue);
            $buckets[$bucket] = $buckets[$bucket]->add($outstanding);

            $lines[] = [
                'invoice_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'party_id' => $invoice->party_id,
                'posting_date' => $invoice->posting_date->toDateString(),
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
                'outstanding' => (string) $outstanding,
            ];
        }

        return [
            'as_of_date' => $asOfDate->toDateString(),
            'lines' => $lines,
            'buckets' => array_map(fn (Money $m) => (string) $m, $buckets),
        ];
    }

    /**
     * §10.1 names this the same shape as AR Aging ("ProgressClaim.net_payable
     * less PaymentAllocation sums") but PaymentAllocation only ever applies
     * to Invoice (§3.9) - subcontractor claims settle via SupplierPayment's
     * own reference_type/reference_id instead. Flagged rather than silently
     * reused: this only ages ProgressClaim-side AP (the subcontractor
     * retention/certification obligation); PurchaseOrder/GRN-driven AP
     * (goods received on credit, settled via SupplierPayment against
     * PurchaseOrder) has no stored net_payable-equivalent to age against
     * and isn't included here - a real, named gap, not a silent omission.
     */
    public function apAging(Tenant $tenant, \Carbon\CarbonInterface $asOfDate): array
    {
        $claims = ProgressClaim::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'certified')
            ->get();

        $lines = [];
        $buckets = ['current' => Money::zero(), 'days_1_30' => Money::zero(), 'days_31_60' => Money::zero(), 'days_61_90' => Money::zero(), 'days_90_plus' => Money::zero()];

        foreach ($claims as $claim) {
            $paid = Money::sum(...SupplierPayment::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('reference_type', ProgressClaim::class)
                ->where('reference_id', $claim->id)
                ->get()->map(fn (SupplierPayment $p) => $p->amount)->all());
            $outstanding = $claim->net_payable->sub($paid);

            if (! $outstanding->isPositive()) {
                continue;
            }

            $referenceDate = $claim->journalEntry?->posting_date ?? $claim->created_at->toDateString();
            $referenceDate = $referenceDate instanceof \Carbon\CarbonInterface ? $referenceDate : \Carbon\Carbon::parse($referenceDate);
            $daysOverdue = (int) $referenceDate->diffInDays($asOfDate, false);
            $bucket = $this->agingBucket($daysOverdue);
            $buckets[$bucket] = $buckets[$bucket]->add($outstanding);

            $lines[] = [
                'progress_claim_id' => $claim->id,
                'subcontract_id' => $claim->subcontract_id,
                'posting_date' => $referenceDate->toDateString(),
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
                'outstanding' => (string) $outstanding,
            ];
        }

        return [
            'as_of_date' => $asOfDate->toDateString(),
            'lines' => $lines,
            'buckets' => array_map(fn (Money $m) => (string) $m, $buckets),
        ];
    }

    private function agingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'days_90_plus',
        };
    }

    // =========================================================================
    // Party Ledger (Counterparty Statement)
    // =========================================================================

    /**
     * §10.1: "Every Invoice/CreditNote/DebitNote/Payment/PaymentAllocation
     * for one Party, in date order, with a running balance." Invoices and
     * DebitNotes increase what the party owes; CreditNotes and Payments
     * decrease it - a running balance in the same sense an AP clerk
     * reconciles a supplier statement against.
     */
    public function partyLedger(Party $party, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): array
    {
        $entries = [];

        foreach (Invoice::withoutGlobalScopes()->where('tenant_id', $party->tenant_id)->where('party_id', $party->id)
            ->whereBetween('posting_date', [$start->toDateString(), $end->toDateString()])->get() as $invoice) {
            $entries[] = ['date' => $invoice->posting_date->toDateString(), 'type' => 'Invoice', 'reference' => $invoice->document_number ?? "#{$invoice->id}", 'amount' => $invoice->net_payable];
        }

        foreach (\App\Models\CreditNote::withoutGlobalScopes()->whereHas('invoice', fn ($q) => $q->where('tenant_id', $party->tenant_id)->where('party_id', $party->id))
            ->whereBetween('posting_date', [$start->toDateString(), $end->toDateString()])->get() as $note) {
            $entries[] = ['date' => $note->posting_date->toDateString(), 'type' => 'CreditNote', 'reference' => $note->document_number ?? "#{$note->id}", 'amount' => $note->amount->negate()];
        }

        foreach (\App\Models\DebitNote::withoutGlobalScopes()->whereHas('invoice', fn ($q) => $q->where('tenant_id', $party->tenant_id)->where('party_id', $party->id))
            ->whereBetween('posting_date', [$start->toDateString(), $end->toDateString()])->get() as $note) {
            $entries[] = ['date' => $note->posting_date->toDateString(), 'type' => 'DebitNote', 'reference' => $note->document_number ?? "#{$note->id}", 'amount' => $note->amount];
        }

        foreach (\App\Models\Payment::withoutGlobalScopes()->where('tenant_id', $party->tenant_id)->where('party_id', $party->id)->where('direction', 'receipt')
            ->whereBetween('posting_date', [$start->toDateString(), $end->toDateString()])->get() as $payment) {
            $entries[] = ['date' => $payment->posting_date->toDateString(), 'type' => 'Payment', 'reference' => "#{$payment->id}", 'amount' => $payment->amount->negate()];
        }

        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        $running = Money::zero();
        foreach ($entries as &$entry) {
            $running = $running->add($entry['amount']);
            $entry['amount'] = (string) $entry['amount'];
            $entry['running_balance'] = (string) $running;
        }

        return ['party_id' => $party->id, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'entries' => $entries, 'closing_balance' => (string) $running];
    }

    // =========================================================================
    // Balance Sheet
    // =========================================================================

    /**
     * §10.1: Retained Earnings is computed at report time, never
     * physically closed - Retained Earnings (Opening) balance plus every
     * posted Revenue JournalLine less every posted Expense JournalLine
     * from inception to the report date. The 'Retained Earnings' account
     * itself (as opposed to 'Retained Earnings (Opening)') is excluded
     * from the raw equity sum below and replaced by this computed figure,
     * so it's never double-counted.
     */
    public function balanceSheet(Tenant $tenant, \Carbon\CarbonInterface $asOfDate): array
    {
        $balances = $this->accountBalancesAsOf($tenant, $asOfDate);

        $assets = $this->groupByType($balances, 'asset');
        $liabilities = $this->groupByType($balances, 'liability');
        $equity = $this->groupByType($balances, 'equity', excludeNames: ['Retained Earnings']);

        $openingRE = $balances->firstWhere('name', 'Retained Earnings (Opening)');
        $openingRE = $openingRE ? Money::fromCents((int) $openingRE->credit_cents - (int) $openingRE->debit_cents) : Money::zero();

        $netIncomeSinceInception = $this->netIncome($tenant, null, $asOfDate);
        $retainedEarningsComputed = $openingRE->add($netIncomeSinceInception);

        $totalAssets = Money::sum(...array_map(fn ($l) => Money::fromMajor($l['balance']), $assets));
        $totalLiabilities = Money::sum(...array_map(fn ($l) => Money::fromMajor($l['balance']), $liabilities));
        $totalEquity = Money::sum(...array_map(fn ($l) => Money::fromMajor($l['balance']), $equity))->add($retainedEarningsComputed);

        return [
            'as_of_date' => $asOfDate->toDateString(),
            'assets' => $assets,
            'total_assets' => (string) $totalAssets,
            'liabilities' => $liabilities,
            'total_liabilities' => (string) $totalLiabilities,
            'equity' => $equity,
            'retained_earnings' => (string) $retainedEarningsComputed,
            'total_equity' => (string) $totalEquity,
            'total_liabilities_and_equity' => (string) $totalLiabilities->add($totalEquity),
            'balanced' => $totalAssets->equals($totalLiabilities->add($totalEquity)),
        ];
    }

    private function accountBalancesAsOf(Tenant $tenant, \Carbon\CarbonInterface $asOfDate)
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenant->id)
            ->where('je.posting_date', '<=', $asOfDate->toDateString())
            ->groupBy('coa.id', 'coa.name', 'coa.account_type', 'coa.sub_type')
            ->selectRaw('coa.id, coa.name, coa.account_type, coa.sub_type, COALESCE(SUM(jl.debit_cents),0) as debit_cents, COALESCE(SUM(jl.credit_cents),0) as credit_cents')
            ->get();
    }

    /** @return array<int, array{account_id: int, name: string, sub_type: ?string, balance: string}> */
    private function groupByType($balances, string $type, array $excludeNames = []): array
    {
        return $balances->where('account_type', $type)
            ->reject(fn ($r) => in_array($r->name, $excludeNames, true))
            ->map(function ($r) use ($type) {
                $balance = in_array($type, self::DEBIT_NORMAL, true)
                    ? (int) $r->debit_cents - (int) $r->credit_cents
                    : (int) $r->credit_cents - (int) $r->debit_cents;

                return ['account_id' => $r->id, 'name' => $r->name, 'sub_type' => $r->sub_type, 'balance' => (string) Money::fromCents($balance)];
            })
            ->values()->all();
    }

    // =========================================================================
    // Income Statement / P&L (and Project P&L - same query, analytic-filtered)
    // =========================================================================

    public function incomeStatement(Tenant $tenant, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end, ?AnalyticAccount $analyticAccount = null): array
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenant->id)
            ->whereBetween('je.posting_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('coa.account_type', ['revenue', 'expense']);

        if ($analyticAccount) {
            $query->where('jl.analytic_account_id', $analyticAccount->id);
        }

        $rows = $query->groupBy('coa.id', 'coa.name', 'coa.account_type')
            ->selectRaw('coa.id, coa.name, coa.account_type, COALESCE(SUM(jl.debit_cents),0) as debit_cents, COALESCE(SUM(jl.credit_cents),0) as credit_cents')
            ->get();

        $revenue = $rows->where('account_type', 'revenue')->map(fn ($r) => [
            'account_id' => $r->id, 'name' => $r->name, 'amount' => (string) Money::fromCents((int) $r->credit_cents - (int) $r->debit_cents),
        ])->values()->all();

        $expenses = $rows->where('account_type', 'expense')->map(fn ($r) => [
            'account_id' => $r->id, 'name' => $r->name, 'amount' => (string) Money::fromCents((int) $r->debit_cents - (int) $r->credit_cents),
        ])->values()->all();

        $totalRevenue = Money::sum(...array_map(fn ($l) => Money::fromMajor($l['amount']), $revenue));
        $totalExpenses = Money::sum(...array_map(fn ($l) => Money::fromMajor($l['amount']), $expenses));

        return [
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
            'analytic_account_id' => $analyticAccount?->id,
            'revenue' => $revenue, 'total_revenue' => (string) $totalRevenue,
            'expenses' => $expenses, 'total_expenses' => (string) $totalExpenses,
            'net_income' => (string) $totalRevenue->sub($totalExpenses),
        ];
    }

    /** Net income (revenue - expense) between two dates, or from inception if $start is null - used by balanceSheet()'s computed Retained Earnings. */
    private function netIncome(Tenant $tenant, ?\Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): Money
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenant->id)
            ->where('je.posting_date', '<=', $end->toDateString())
            ->whereIn('coa.account_type', ['revenue', 'expense']);

        if ($start) {
            $query->where('je.posting_date', '>=', $start->toDateString());
        }

        $row = $query->selectRaw("
            COALESCE(SUM(CASE WHEN coa.account_type = 'revenue' THEN jl.credit_cents - jl.debit_cents ELSE 0 END), 0) as revenue_cents,
            COALESCE(SUM(CASE WHEN coa.account_type = 'expense' THEN jl.debit_cents - jl.credit_cents ELSE 0 END), 0) as expense_cents
        ")->first();

        return Money::fromCents((int) $row->revenue_cents - (int) $row->expense_cents);
    }

    // =========================================================================
    // General Ledger - Detail and Summary
    // =========================================================================

    public function generalLedgerDetail(ChartOfAccount $account, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): array
    {
        $opening = $this->accountBalanceBetween($account, null, $start->copy()->subDay());

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.tenant_id', $account->tenant_id)
            ->where('jl.account_id', $account->id)
            ->whereBetween('je.posting_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('je.posting_date')->orderBy('jl.id')
            ->select('je.posting_date', 'je.event_type', 'je.reference_type', 'je.reference_id', 'jl.debit_cents', 'jl.credit_cents')
            ->get();

        $debitNormal = in_array($account->account_type, self::DEBIT_NORMAL, true);
        $running = $opening;
        $lines = [];
        foreach ($rows as $r) {
            $delta = Money::fromCents($debitNormal ? (int) $r->debit_cents - (int) $r->credit_cents : (int) $r->credit_cents - (int) $r->debit_cents);
            $running = $running->add($delta);
            $lines[] = [
                'posting_date' => $r->posting_date, 'event_type' => $r->event_type,
                'reference_type' => $r->reference_type, 'reference_id' => $r->reference_id,
                'debit' => (string) Money::fromCents((int) $r->debit_cents), 'credit' => (string) Money::fromCents((int) $r->credit_cents),
                'running_balance' => (string) $running,
            ];
        }

        return [
            'account_id' => $account->id, 'account_name' => $account->name,
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
            'opening_balance' => (string) $opening, 'lines' => $lines, 'closing_balance' => (string) $running,
        ];
    }

    public function generalLedgerSummary(Tenant $tenant, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): array
    {
        $accounts = ChartOfAccount::where('tenant_id', $tenant->id)->orderBy('code')->get();

        $summary = [];
        foreach ($accounts as $account) {
            $opening = $this->accountBalanceBetween($account, null, $start->copy()->subDay());

            $movement = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('jl.tenant_id', $tenant->id)->where('jl.account_id', $account->id)
                ->whereBetween('je.posting_date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('COALESCE(SUM(jl.debit_cents),0) as debit_cents, COALESCE(SUM(jl.credit_cents),0) as credit_cents')
                ->first();

            if ((int) $movement->debit_cents === 0 && (int) $movement->credit_cents === 0 && $opening->isZero()) {
                continue;
            }

            $debitNormal = in_array($account->account_type, self::DEBIT_NORMAL, true);
            $periodMovement = Money::fromCents($debitNormal
                ? (int) $movement->debit_cents - (int) $movement->credit_cents
                : (int) $movement->credit_cents - (int) $movement->debit_cents);
            $closing = $opening->add($periodMovement);

            $summary[] = [
                'account_id' => $account->id, 'name' => $account->name, 'account_type' => $account->account_type,
                'opening_balance' => (string) $opening,
                'period_debit' => (string) Money::fromCents((int) $movement->debit_cents),
                'period_credit' => (string) Money::fromCents((int) $movement->credit_cents),
                'closing_balance' => (string) $closing,
            ];
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'accounts' => $summary];
    }

    private function accountBalanceBetween(ChartOfAccount $account, ?\Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): Money
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.tenant_id', $account->tenant_id)->where('jl.account_id', $account->id)
            ->where('je.posting_date', '<=', $end->toDateString());

        if ($start) {
            $query->where('je.posting_date', '>=', $start->toDateString());
        }

        $row = $query->selectRaw('COALESCE(SUM(jl.debit_cents),0) as debit_cents, COALESCE(SUM(jl.credit_cents),0) as credit_cents')->first();

        $debitNormal = in_array($account->account_type, self::DEBIT_NORMAL, true);

        return Money::fromCents($debitNormal
            ? (int) $row->debit_cents - (int) $row->credit_cents
            : (int) $row->credit_cents - (int) $row->debit_cents);
    }

    // =========================================================================
    // Cash Flow Statement
    // =========================================================================

    private const OPERATING_EVENT_TYPES = [
        'payment_received', 'payment_made', 'net_pay_disbursed', 'statutory_remittance',
        'other_deductions_remitted', 'retention_released_client', 'retention_released_subcontractor',
        'customer_advance_received', 'customer_advance_refunded',
    ];

    private const INVESTING_EVENT_TYPES = ['asset_acquired', 'asset_disposed'];

    private const FINANCING_EVENT_TYPES = ['capital_movement'];

    /**
     * §10.1/§7: categorization is a lookup keyed on JournalEntry.event_type,
     * applied only to JournalLine rows that actually hit a Cash/Bank
     * account - an asset bought on credit never appears until the cash
     * actually moves. No tenant in this codebase ever splits 'Cash/Bank'
     * into sub_type children (§3.1's split is optional and unused here),
     * so filtering on the account name is sufficient.
     */
    public function cashFlowStatement(Tenant $tenant, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenant->id)
            ->where('coa.name', 'Cash/Bank')
            ->whereBetween('je.posting_date', [$start->toDateString(), $end->toDateString()])
            ->select('je.event_type', 'jl.debit_cents', 'jl.credit_cents')
            ->get();

        $totals = ['operating' => Money::zero(), 'investing' => Money::zero(), 'financing' => Money::zero(), 'uncategorized' => Money::zero()];

        foreach ($rows as $r) {
            $netCash = Money::fromCents((int) $r->debit_cents - (int) $r->credit_cents);
            $category = match (true) {
                in_array($r->event_type, self::OPERATING_EVENT_TYPES, true) => 'operating',
                in_array($r->event_type, self::INVESTING_EVENT_TYPES, true) => 'investing',
                in_array($r->event_type, self::FINANCING_EVENT_TYPES, true) => 'financing',
                default => 'uncategorized',
            };
            $totals[$category] = $totals[$category]->add($netCash);
        }

        $netChange = Money::sum(...array_values($totals));

        return [
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
            'operating' => (string) $totals['operating'],
            'investing' => (string) $totals['investing'],
            'financing' => (string) $totals['financing'],
            'uncategorized' => (string) $totals['uncategorized'],
            'net_change_in_cash' => (string) $netChange,
        ];
    }

    // =========================================================================
    // Cash Position (Master Dashboard widget, §10)
    // =========================================================================

    /**
     * §10: "sum of Cash/Bank JournalLine balances for the tenant, as of
     * now - a live snapshot at read time." Also broken down per
     * BankAccount, since the dashboard widget's own definition names
     * "across BankAccounts."
     */
    public function cashPosition(Tenant $tenant): array
    {
        $cashAccount = ChartOfAccount::where('tenant_id', $tenant->id)->where('name', 'Cash/Bank')->first();
        $total = $cashAccount ? $this->accountBalanceBetween($cashAccount, null, BusinessTime::today()) : Money::zero();

        $bankAccounts = \App\Models\BankAccount::where('tenant_id', $tenant->id)->where('status', 'active')->get()
            ->map(fn ($ba) => ['id' => $ba->id, 'bank_name' => $ba->bank_name, 'account_number' => $ba->account_number]);

        return ['as_of' => now()->toDateTimeString(), 'total' => (string) $total, 'bank_accounts' => $bankAccounts->all()];
    }
}
