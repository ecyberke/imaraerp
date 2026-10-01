<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Services\SupplierReturnService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierReturnController extends Controller
{
    public function __construct(private SupplierReturnService $returns)
    {
    }

    public function store(Request $request)
    {
        $this->authorize('create', SupplierReturn::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'already_paid' => ['nullable', 'boolean'],
        ]);

        $item = Item::where('tenant_id', $tenantId)->findOrFail($data['item_id']);
        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        $return = $this->returns->returnToSupplier(
            $item, $warehouse, (string) $data['quantity'], Money::fromMajor($data['unit_cost']), $data['already_paid'] ?? false,
        );

        return response()->json($return, 201);
    }
}
