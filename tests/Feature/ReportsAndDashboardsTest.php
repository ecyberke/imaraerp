<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Party;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProgressClaimService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * phase1-reports-dashboards exit criterion (execution_plan.md): "Trial
 * Balance nets to zero against real posted data; Balance Sheet and
 * Income Statement tie out against it; Cash Flow Statement's operating/
 * investing totals reconcile against actual BankAccount movements for
 * a test period."
 */
class ReportsAndDashboardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Currency $kes;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
        $this->customer = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jengo Ltd', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified', 'credit_limit_cents' => Money::fromMajor('1000000'),
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'),
            'mfa_enabled' => in_array($role, ['finance', 'admin'], true),
        ]);
    }

    private function headersFor(string $email, string $role): array
    {
        $user = $this->makeUser($email, $role);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeSalesOrder(): SalesOrder
    {
        return SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed', 'invoice_policy' => 'on_order', 'status' => 'approved',
            'currency_id' => $this->kes->id, 'exchange_rate' => 1,
        ]);
    }

    // =========================================================================
    // Exit criterion: Trial Balance / Balance Sheet / Income Statement / Cash Flow
    // =========================================================================

    public function test_trial_balance_balance_sheet_income_statement_and_cash_flow_tie_out(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');

        $bankAccount = $this->withHeaders($headers)->postJson('/api/bank-accounts', [
            'bank_name' => 'Equity Bank', 'account_number' => '0123456789',
            'currency_id' => $this->kes->id,
            'gl_account_id' => \App\Models\ChartOfAccount::where('tenant_id', $this->tenant->id)->where('name', 'Cash/Bank')->value('id'),
        ])->assertCreated();

        // Invoice raised and fully paid (Revenue + VAT + AR clearing to cash).
        $salesOrder = $this->makeSalesOrder();
        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Cement delivery', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();
        $netPayable = $invoice->json('net_payable'); // 116000.00

        $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'invoice_allocations' => [['invoice_id' => $invoice->json('id'), 'amount' => $netPayable]],
            'method' => 'bank_transfer', 'bank_account_id' => $bankAccount->json('id'),
        ])->assertCreated();

        // Owner capital injection (financing cash flow).
        $this->withHeaders($headers)->postJson('/api/capital-movements', [
            'direction' => 'capital_injected', 'amount' => 50000,
        ])->assertCreated();

        // Trial Balance nets to zero.
        $trialBalance = $this->withHeaders($headers)->getJson('/api/reports/trial-balance?as_of_date='.now()->toDateString())->assertOk();
        $this->assertTrue($trialBalance->json('balanced'));
        $this->assertSame($trialBalance->json('total_debits'), $trialBalance->json('total_credits'));

        // Balance Sheet balances (Assets = Liabilities + Equity).
        $balanceSheet = $this->withHeaders($headers)->getJson('/api/reports/balance-sheet?as_of_date='.now()->toDateString())->assertOk();
        $this->assertTrue($balanceSheet->json('balanced'));
        $this->assertSame($balanceSheet->json('total_assets'), $balanceSheet->json('total_liabilities_and_equity'));

        // Income Statement, run from well before inception to today, ties
        // out with the Balance Sheet's computed Retained Earnings - a
        // fresh tenant with no OpeningBalanceBatch means "since inception"
        // and "this period" are the same window.
        $incomeStatement = $this->withHeaders($headers)->getJson('/api/reports/income-statement?start=2020-01-01&end='.now()->toDateString())->assertOk();
        $this->assertSame('100000.00', $incomeStatement->json('total_revenue'));
        $this->assertSame($incomeStatement->json('net_income'), $balanceSheet->json('retained_earnings'));

        // Cash Flow: operating = the invoice payment (116000 in);
        // financing = the capital injection (50000 in); investing = 0.00
        // (no Asset entity exists yet in this codebase to generate an
        // investing cash flow event).
        $cashFlow = $this->withHeaders($headers)->getJson('/api/reports/cash-flow-statement?start=2020-01-01&end='.now()->toDateString())->assertOk();
        $this->assertSame('116000.00', $cashFlow->json('operating'));
        $this->assertSame('0.00', $cashFlow->json('investing'));
        $this->assertSame('50000.00', $cashFlow->json('financing'));

        // Reconciles against actual BankAccount-linked Payment movements
        // for the period: the one receipt recorded against this bank
        // account sums to exactly the operating total above.
        $bankMovements = \App\Models\Payment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('bank_account_id', $bankAccount->json('id'))
            ->get()->sum(fn ($p) => (float) (string) $p->amount);
        $this->assertSame(116000.0, $bankMovements);
    }

    // =========================================================================
    // AR Aging / Party Ledger
    // =========================================================================

    public function test_ar_aging_buckets_an_unpaid_overdue_invoice(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $salesOrder = $this->makeSalesOrder();

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Overdue job', 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        $farFuture = now()->addDays(45)->toDateString();
        $aging = $this->withHeaders($headers)->getJson("/api/reports/ar-aging?as_of_date={$farFuture}")->assertOk();

        $line = collect($aging->json('lines'))->firstWhere('invoice_id', $invoice->json('id'));
        $this->assertNotNull($line);
        $this->assertSame('days_31_60', $line['bucket']);
        $this->assertSame('5800.00', $line['outstanding']); // gross 5000 + 16% VAT
    }

    public function test_party_ledger_shows_chronological_entries_with_running_balance(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $salesOrder = $this->makeSalesOrder();

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Ledger test', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'invoice_allocations' => [['invoice_id' => $invoice->json('id'), 'amount' => '6000']],
            'method' => 'cash',
        ])->assertCreated();

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();
        $ledger = $this->withHeaders($headers)
            ->getJson("/api/reports/party-ledger?party_id={$this->customer->id}&start={$start}&end={$end}")
            ->assertOk();

        $this->assertCount(2, $ledger->json('entries'));
        $this->assertSame('Invoice', $ledger->json('entries.0.type'));
        $this->assertSame('11600.00', $ledger->json('entries.0.running_balance'));
        $this->assertSame('Payment', $ledger->json('entries.1.type'));
        $this->assertSame('5600.00', $ledger->json('entries.1.running_balance'));
        $this->assertSame('5600.00', $ledger->json('closing_balance'));
    }

    // =========================================================================
    // General Ledger - Detail and Summary
    // =========================================================================

    public function test_general_ledger_detail_and_summary_track_the_same_movement(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $salesOrder = $this->makeSalesOrder();

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'GL test', 'quantity' => 1, 'unit_price' => 2000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        $arAccountId = \App\Models\ChartOfAccount::where('tenant_id', $this->tenant->id)->where('name', 'Accounts Receivable')->value('id');
        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        $detail = $this->withHeaders($headers)
            ->getJson("/api/reports/general-ledger-detail?account_id={$arAccountId}&start={$start}&end={$end}")->assertOk();
        $this->assertSame('0.00', $detail->json('opening_balance'));
        $this->assertCount(1, $detail->json('lines'));
        $this->assertSame('2320.00', $detail->json('closing_balance')); // 2000 + 16% VAT

        $summary = $this->withHeaders($headers)->getJson("/api/reports/general-ledger-summary?start={$start}&end={$end}")->assertOk();
        $arRow = collect($summary->json('accounts'))->firstWhere('account_id', $arAccountId);
        $this->assertSame('2320.00', $arRow['closing_balance']);
    }

    // =========================================================================
    // Project P&L
    // =========================================================================

    public function test_project_pnl_filters_income_statement_by_analytic_account(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');

        $analyticAccount = \App\Models\AnalyticAccount::create([
            'tenant_id' => $this->tenant->id, 'cost_code' => 'PROJ-1', 'name' => 'Project One',
        ]);

        $subcontract = Subcontract::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->supplier(), 'status' => 'active']);
        $claim = \App\Models\ProgressClaim::create([
            'tenant_id' => $this->tenant->id, 'subcontract_id' => $subcontract->id,
            'period' => '2026-09', 'amount_claimed_cents' => Money::fromMajor('10000'),
        ]);
        // Certify without an analytic tag first - this claim's Subcontract
        // Expense line should NOT show up in the project-tagged P&L.
        app(ProgressClaimService::class)->certify($claim, Money::fromMajor('10000'), '0.16', '0.10');

        // A directly-posted, project-tagged Revenue line via a raised
        // Invoice has no analytic tagging path yet in InvoiceService
        // (crm-sales-boq/finance-billing don't wire SalesOrder->Project
        // yet - Project doesn't exist), so this test proves the FILTER
        // itself works correctly by asserting the untagged claim's
        // Subcontract Expense is correctly excluded, not by tagging real
        // revenue to it.
        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();
        $projectPnl = $this->withHeaders($headers)
            ->getJson("/api/reports/project-pnl?analytic_account_id={$analyticAccount->id}&start={$start}&end={$end}")
            ->assertOk();

        $this->assertSame('0.00', $projectPnl->json('total_expenses'));
        $this->assertSame([], $projectPnl->json('expenses'));

        $untagged = $this->withHeaders($headers)->getJson("/api/reports/income-statement?start={$start}&end={$end}")->assertOk();
        $this->assertGreaterThan(0, count($untagged->json('expenses')));
    }

    private function supplier(): int
    {
        return Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Steel & Co', 'type' => 'supplier',
            'tax_residency_status' => 'resident_certified',
        ])->id;
    }

    // =========================================================================
    // Dashboards
    // =========================================================================

    public function test_master_dashboard_returns_cash_position_and_pending_approvals(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');

        $bankAccount = $this->withHeaders($headers)->postJson('/api/bank-accounts', [
            'bank_name' => 'KCB', 'account_number' => '999888777',
            'currency_id' => $this->kes->id,
            'gl_account_id' => \App\Models\ChartOfAccount::where('tenant_id', $this->tenant->id)->where('name', 'Cash/Bank')->value('id'),
        ])->assertCreated();

        $salesOrder = $this->makeSalesOrder();
        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Dashboard test', 'quantity' => 1, 'unit_price' => 3000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();
        $this->withHeaders($headers)->postJson('/api/payments', [
            'party_id' => $this->customer->id,
            'invoice_allocations' => [['invoice_id' => $invoice->json('id'), 'amount' => $invoice->json('net_payable')]],
            'method' => 'bank_transfer', 'bank_account_id' => $bankAccount->json('id'),
        ])->assertCreated();

        $master = $this->withHeaders($headers)->getJson('/api/dashboards/master')->assertOk();
        $this->assertSame('3480.00', $master->json('cash_position.total'));
        $this->assertCount(1, $master->json('cash_position.bank_accounts'));
    }

    public function test_crm_sales_dashboard_reports_supply_path_breakdown(): void
    {
        $this->makeSalesOrder();
        $headers = $this->headersFor('sales@example.com', 'sales');

        $dashboard = $this->withHeaders($headers)->getJson('/api/dashboards/crm-sales')->assertOk();
        $this->assertSame(1, $dashboard->json('orders_by_supply_path.direct_sale'));
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_trial_balance_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $salesOrder = $this->makeSalesOrder();
        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Isolated', 'quantity' => 1, 'unit_price' => 9000]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'finance')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Finance', 'email' => 'other-finance@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => true,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $otherTrialBalance = $this->withHeaders($otherHeaders)
            ->getJson('/api/reports/trial-balance?as_of_date='.now()->toDateString())->assertOk();
        $this->assertSame([], $otherTrialBalance->json('lines'));
        $this->assertSame('0.00', $otherTrialBalance->json('total_debits'));
    }
}
