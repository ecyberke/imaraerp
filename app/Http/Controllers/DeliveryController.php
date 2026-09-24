<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    public function store(Request $request, SalesOrder $salesOrder)
    {
        $this->authorize('create', Delivery::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ]);

        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        $delivery = $this->deliveries->createDelivery($salesOrder, $warehouse);

        return response()->json($delivery, 201);
    }

    public function addLine(Request $request, Delivery $delivery)
    {
        $this->authorize('update', $delivery);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'sales_order_line_id' => ['required', 'integer', Rule::exists('sales_order_lines', 'id')->where('tenant_id', $tenantId)],
            'quantity_delivered' => ['required', 'numeric', 'gt:0'],
        ]);

        $line = SalesOrderLine::where('tenant_id', $tenantId)->findOrFail($data['sales_order_line_id']);

        $deliveryLine = $this->deliveries->addLine($delivery, $line, $data['quantity_delivered']);

        return response()->json($deliveryLine, 201);
    }

    public function markDelivered(Delivery $delivery)
    {
        $this->authorize('update', $delivery);

        return $this->deliveries->markDelivered($delivery);
    }

    public function show(Delivery $delivery)
    {
        $this->authorize('view', $delivery);

        return $delivery->load('lines');
    }

    /**
     * phase1-screens: the Quotation detail page needs to render every
     * Delivery already created against an order to render its
     * deliveries section - no list-by-order endpoint existed until now.
     */
    public function indexForSalesOrder(Request $request, SalesOrder $salesOrder)
    {
        $this->authorize('viewAny', Delivery::class);

        return $salesOrder->deliveries()->with('lines')->get();
    }
}
