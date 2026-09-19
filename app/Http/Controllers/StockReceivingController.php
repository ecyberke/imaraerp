<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\QualityCheck;
use App\Models\StockQuarantine;
use App\Models\Warehouse;
use App\Services\StockReceivingService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Simulates the GRN-receipt -> QC round trip (see StockReceivingService's
 * docblock for why - GoodsReceiptNote doesn't exist until procurement).
 */
class StockReceivingController extends Controller
{
    public function __construct(private StockReceivingService $receiving)
    {
    }

    public function storeQuarantine(Request $request)
    {
        $this->authorize('create', StockQuarantine::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            // A transactional quantity - rejects zero/negative outright (§1.1).
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $item = Item::where('tenant_id', $tenantId)->findOrFail($data['item_id']);
        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        $quarantine = $this->receiving->receiveIntoQuarantine(
            $item, $warehouse, (string) $data['quantity'], Money::fromMajor($data['unit_cost']),
        );

        return response()->json($quarantine, 201);
    }

    public function showQuarantine(StockQuarantine $stockQuarantine)
    {
        $this->authorize('view', $stockQuarantine);

        return $stockQuarantine;
    }

    public function storeQualityCheck(Request $request)
    {
        $this->authorize('create', QualityCheck::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'stock_quarantine_id' => ['required', 'integer', Rule::exists('stock_quarantines', 'id')->where('tenant_id', $tenantId)],
            'result' => ['required', 'in:pass,fail'],
            'disposition' => ['required', 'in:accept,rework,scrap'],
        ]);

        $quarantine = StockQuarantine::where('tenant_id', $tenantId)->findOrFail($data['stock_quarantine_id']);

        $qualityCheck = $this->receiving->recordQualityCheck(
            $quarantine, $data['result'], $data['disposition'], $request->user(),
        );

        return response()->json($qualityCheck->load('checkable'), 201);
    }
}
