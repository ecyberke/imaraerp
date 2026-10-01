<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\StockAvailabilityService;
use App\Services\StockValuationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockAvailabilityController extends Controller
{
    public function __construct(
        private StockAvailabilityService $availability,
        private StockValuationService $valuation,
    ) {
    }

    public function show(Request $request)
    {
        // Reuses WarehousePolicy::viewAny - same read-access role set this
        // query needs (Admin/Warehouse/Procurement/Sales), no dedicated
        // policy needed for a read-only computed endpoint with no model
        // of its own to authorize against.
        $this->authorize('viewAny', Warehouse::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ]);

        $item = Item::with('category')->where('tenant_id', $tenantId)->findOrFail($data['item_id']);
        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        return response()->json([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'on_hand_quantity' => $this->valuation->onHandQuantity($item, $warehouse),
            'on_hand_value' => (string) $this->valuation->onHandValue($item, $warehouse),
            'available_quantity' => $this->availability->availableQuantity($item, $warehouse),
        ]);
    }
}
