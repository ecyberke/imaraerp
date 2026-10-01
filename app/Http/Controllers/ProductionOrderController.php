<?php

namespace App\Http\Controllers;

use App\Models\BillOfMaterial;
use App\Models\ProductionOrder;
use App\Models\QualityCheck;
use App\Models\Warehouse;
use App\Services\ProductionOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionOrderService $productionOrders) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ProductionOrder::class);

        return ProductionOrder::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', ProductionOrder::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'bom_id' => ['required', 'integer', Rule::exists('bill_of_materials', 'id')->where('tenant_id', $tenantId)],
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        BillOfMaterial::where('tenant_id', $tenantId)->findOrFail($data['bom_id']);
        Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        $order = ProductionOrder::create([...$data, 'tenant_id' => $tenantId, 'status' => 'draft']);

        return response()->json($order, 201);
    }

    public function show(ProductionOrder $productionOrder)
    {
        $this->authorize('view', $productionOrder);

        return $productionOrder->load('qualityChecks');
    }

    /**
     * @param  array<string, string>  $actual_quantities  bill_of_material_line_id (as a string key) => actual quantity consumed
     */
    public function complete(Request $request, ProductionOrder $productionOrder)
    {
        $this->authorize('update', $productionOrder);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'actual_quantities' => ['nullable', 'array'],
            'actual_quantities.*' => ['numeric', 'gt:0'],
        ]);

        // Keys arrive as strings over JSON/form data - re-key by int so
        // the service's bill_of_material_line_id => qty lookup matches.
        $actualQuantities = [];
        foreach ($data['actual_quantities'] ?? [] as $lineId => $qty) {
            $actualQuantities[(int) $lineId] = $qty;
        }

        $order = $this->productionOrders->complete($productionOrder, $actualQuantities, $request->user());

        return response()->json($order);
    }

    public function recordQualityCheck(Request $request, ProductionOrder $productionOrder)
    {
        $this->authorize('create', QualityCheck::class);

        $data = $request->validate([
            'result' => ['required', 'string', Rule::in(['pass', 'fail'])],
            'disposition' => ['required', 'string', Rule::in(['accept', 'rework', 'scrap'])],
        ]);

        $qc = $this->productionOrders->recordQualityCheck($productionOrder, $data['result'], $data['disposition'], $request->user());

        return response()->json($qc, 201);
    }
}
