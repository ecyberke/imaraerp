<?php

namespace App\Http\Controllers;

use App\Models\AnalyticAccount;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\Party;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §10.1's own parameter convention: point-in-time reports default to
 * the last closed AccountingPeriod; period reports default to the
 * current open period. Authorization reuses InvoicePolicy::viewAny
 * (Admin/Finance, §11) rather than a dedicated policy per report - no
 * report here has a model of its own to authorize against, same reason
 * StockAvailabilityController reuses WarehousePolicy::viewAny.
 */
class ReportController extends Controller
{
    public function __construct(private ReportingService $reports) {}

    private function asOfDate(Request $request, $tenant): \Carbon\CarbonInterface
    {
        return $request->filled('as_of_date') ? \Carbon\Carbon::parse($request->query('as_of_date')) : $this->reports->defaultAsOfDate($tenant);
    }

    /** @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface} */
    private function range(Request $request, $tenant): array
    {
        if ($request->filled('start') && $request->filled('end')) {
            return [\Carbon\Carbon::parse($request->query('start')), \Carbon\Carbon::parse($request->query('end'))];
        }

        return $this->reports->defaultPeriodRange($tenant);
    }

    public function trialBalance(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return $this->reports->trialBalance($request->user()->tenant, $this->asOfDate($request, $request->user()->tenant));
    }

    public function arAging(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return $this->reports->arAging($request->user()->tenant, $this->asOfDate($request, $request->user()->tenant));
    }

    public function apAging(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return $this->reports->apAging($request->user()->tenant, $this->asOfDate($request, $request->user()->tenant));
    }

    public function partyLedger(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
        ]);
        $party = Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']);
        [$start, $end] = $this->range($request, $request->user()->tenant);

        return $this->reports->partyLedger($party, $start, $end);
    }

    public function balanceSheet(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return $this->reports->balanceSheet($request->user()->tenant, $this->asOfDate($request, $request->user()->tenant));
    }

    public function incomeStatement(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        [$start, $end] = $this->range($request, $request->user()->tenant);

        return $this->reports->incomeStatement($request->user()->tenant, $start, $end);
    }

    public function projectPnl(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'analytic_account_id' => ['required', 'integer', Rule::exists('analytic_accounts', 'id')->where('tenant_id', $tenantId)],
        ]);
        $analyticAccount = AnalyticAccount::where('tenant_id', $tenantId)->findOrFail($data['analytic_account_id']);
        [$start, $end] = $this->range($request, $request->user()->tenant);

        return $this->reports->incomeStatement($request->user()->tenant, $start, $end, $analyticAccount);
    }

    public function generalLedgerDetail(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)],
        ]);
        $account = ChartOfAccount::where('tenant_id', $tenantId)->findOrFail($data['account_id']);
        [$start, $end] = $this->range($request, $request->user()->tenant);

        return $this->reports->generalLedgerDetail($account, $start, $end);
    }

    public function generalLedgerSummary(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        [$start, $end] = $this->range($request, $request->user()->tenant);

        return $this->reports->generalLedgerSummary($request->user()->tenant, $start, $end);
    }

    public function cashFlowStatement(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        [$start, $end] = $this->range($request, $request->user()->tenant);

        return $this->reports->cashFlowStatement($request->user()->tenant, $start, $end);
    }
}
