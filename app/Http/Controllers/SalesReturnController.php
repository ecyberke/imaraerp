<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesReturnController extends Controller
{
    public function __construct(private SalesReturnService $returns) {}

    public function store(Request $request, Delivery $delivery)
    {
        $this->authorize('create', SalesReturn::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'sales_order_line_id' => ['required', 'integer', Rule::exists('sales_order_lines', 'id')->where('tenant_id', $tenantId)],
            'quantity_returned' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'restock_status' => ['nullable', 'string', Rule::in(['restocked', 'scrapped'])],
        ]);

        $line = SalesOrderLine::where('tenant_id', $tenantId)->findOrFail($data['sales_order_line_id']);

        $return = $this->returns->create(
            $delivery, $line, $data['quantity_returned'], $data['reason'] ?? null, $data['restock_status'] ?? 'restocked',
        );

        return response()->json($return, 201);
    }
}
