<?php

namespace App\Services;

use App\Models\CreditApproval;
use App\Models\DemandTrigger;
use App\Models\FeasibilityAssessment;
use App\Models\Lead;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QualityCheck;
use App\Models\SalesOrder;
use App\Models\StockQuarantine;
use App\Models\StockReservation;
use App\Models\SupplierPerformanceLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * §10: "Dashboards are read-only views over existing tables (no new
 * source-of-truth data)." Only the dashboards whose underlying module
 * actually exists in this codebase are implemented - Manufacturing,
 * Projects, HR & Payroll, and Fixed Assets & Plant have no entities
 * built yet (later branches), so a "dashboard" for them would have
 * nothing real to query; flagged here rather than built against
 * placeholder data. Master, CRM & Sales, Inventory, Procurement, and
 * Finance cover every module this codebase has so far.
 */
class DashboardService
{
    public function __construct(private ReportingService $reports) {}

    public function master(Tenant $tenant): array
    {
        $arAging = $this->reports->arAging($tenant, \App\Support\BusinessTime::today());
        $overdueReceivables = \App\Support\Money::zero();
        foreach (['days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'] as $bucket) {
            $overdueReceivables = $overdueReceivables->add(\App\Support\Money::fromMajor($arAging['buckets'][$bucket]));
        }

        return [
            'open_sales_orders_by_status' => SalesOrder::where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['closed', 'rejected'])
                ->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'cash_position' => $this->reports->cashPosition($tenant),
            'overdue_receivables' => (string) $overdueReceivables,
            'pending_approvals' => [
                'purchase_requisitions' => PurchaseRequisition::where('tenant_id', $tenant->id)->where('status', 'pending_approval')->count(),
                'credit_approvals' => CreditApproval::where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
            ],
            'low_stock_alerts' => DemandTrigger::where('tenant_id', $tenant->id)->where('status', 'open')->count(),
        ];
    }

    public function crmSales(Tenant $tenant): array
    {
        $totalOrders = SalesOrder::where('tenant_id', $tenant->id)->count();
        $approvedOrders = SalesOrder::where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['draft', 'feasibility_check', 'rejected', 'renegotiating'])->count();

        return [
            'lead_pipeline' => Lead::where('tenant_id', $tenant->id)
                ->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'quotation_conversion_rate' => $totalOrders > 0 ? round($approvedOrders / $totalOrders, 4) : 0,
            'feasibility_pass_fail_rate' => FeasibilityAssessment::where('tenant_id', $tenant->id)
                ->select('result', DB::raw('count(*) as count'))->groupBy('result')->pluck('count', 'result'),
            'orders_by_supply_path' => SalesOrder::where('tenant_id', $tenant->id)
                ->select('supply_path', DB::raw('count(*) as count'))->groupBy('supply_path')->pluck('count', 'supply_path'),
        ];
    }

    public function inventory(Tenant $tenant): array
    {
        return [
            'items_below_reorder_level' => DemandTrigger::where('tenant_id', $tenant->id)->where('status', 'open')->count(),
            'quarantine_queue' => StockQuarantine::where('tenant_id', $tenant->id)->where('qc_status', 'pending')->count(),
            'reservation_aging' => StockReservation::where('tenant_id', $tenant->id)->where('status', 'active')
                ->select('reserve_type', DB::raw('count(*) as count'))->groupBy('reserve_type')->pluck('count', 'reserve_type'),
        ];
    }

    public function procurement(Tenant $tenant): array
    {
        return [
            'open_prs_by_status' => PurchaseRequisition::where('tenant_id', $tenant->id)
                ->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'open_pos_by_status' => PurchaseOrder::where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['stocked', 'returned'])
                ->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'goods_in_transit' => PurchaseOrder::where('tenant_id', $tenant->id)
                ->whereIn('status', ['ordered', 'partially_received'])->count(),
            'qc_pass_fail_rate' => QualityCheck::where('tenant_id', $tenant->id)
                ->select('result', DB::raw('count(*) as count'))->groupBy('result')->pluck('count', 'result'),
            'supplier_performance_events' => SupplierPerformanceLog::where('tenant_id', $tenant->id)
                ->select('party_id', 'event_type', DB::raw('count(*) as count'))
                ->groupBy('party_id', 'event_type')->get(),
        ];
    }

    public function finance(Tenant $tenant): array
    {
        [$start, $end] = $this->reports->defaultPeriodRange($tenant);

        return [
            'ar_aging_buckets' => $this->reports->arAging($tenant, \App\Support\BusinessTime::today())['buckets'],
            'ap_aging_buckets' => $this->reports->apAging($tenant, \App\Support\BusinessTime::today())['buckets'],
            'cash_flow_current_period' => $this->reports->cashFlowStatement($tenant, $start, $end),
            'credit_approvals_pending' => CreditApproval::where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
            'journal_entry_volume_by_type' => \App\Models\JournalEntry::where('tenant_id', $tenant->id)
                ->whereBetween('posting_date', [$start->toDateString(), $end->toDateString()])
                ->select('event_type', DB::raw('count(*) as count'))->groupBy('event_type')->pluck('count', 'event_type'),
        ];
    }
}
