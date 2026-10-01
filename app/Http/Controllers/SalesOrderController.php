<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    public function __construct(private SalesOrderService $salesOrders) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SalesOrder::class);

        return SalesOrder::where('tenant_id', $request->user()->tenant_id)->with('lines')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', SalesOrder::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->where('tenant_id', $tenantId)],
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'supply_path' => ['required', 'string', Rule::in(SalesOrder::SUPPLY_PATHS)],
            'invoice_policy' => ['required', 'string', Rule::in(SalesOrder::INVOICE_POLICIES)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
        ]);

        $salesOrder = $this->salesOrders->create(
            $request->user()->tenant,
            [
                'lead_id' => $data['lead_id'] ?? null,
                'party_id' => $data['party_id'],
                'currency_id' => $data['currency_id'],
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'supply_path' => $data['supply_path'],
                'invoice_policy' => $data['invoice_policy'],
            ],
            $data['lines'],
        );

        return response()->json($salesOrder, 201);
    }

    public function show(SalesOrder $salesOrder)
    {
        $this->authorize('view', $salesOrder);

        return $salesOrder->load('lines', 'feasibilityAssessments');
    }

    public function submitForFeasibility(SalesOrder $salesOrder)
    {
        $this->authorize('assessFeasibility', $salesOrder);

        return $this->salesOrders->submitForFeasibility($salesOrder);
    }

    public function assessFeasibility(Request $request, SalesOrder $salesOrder)
    {
        $this->authorize('assessFeasibility', $salesOrder);

        $data = $request->validate([
            'result' => ['required', 'string', Rule::in(['passed', 'rejected', 'renegotiating'])],
            'notes' => ['nullable', 'string'],
            'conditions' => ['nullable', 'string'],
        ]);

        $assessment = $this->salesOrders->recordFeasibilityAssessment(
            $salesOrder, $data['result'], $request->user(), $data['notes'] ?? null, $data['conditions'] ?? null,
        );

        return response()->json($assessment->load('salesOrder'), 201);
    }

    public function resubmit(SalesOrder $salesOrder)
    {
        $this->authorize('assessFeasibility', $salesOrder);

        return $this->salesOrders->resubmitAfterRenegotiation($salesOrder);
    }

    public function reserveStock(Request $request, SalesOrder $salesOrder)
    {
        $this->authorize('reserveStock', $salesOrder);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ]);

        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        $this->salesOrders->reserveStockForDirectSale($salesOrder, $warehouse);

        return response()->json($salesOrder->fresh('lines'));
    }
}
