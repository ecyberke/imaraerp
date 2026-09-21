<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceiptNote;
use App\Models\GRNLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\PurchaseOrderReceivingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoodsReceiptNoteController extends Controller
{
    public function __construct(private PurchaseOrderReceivingService $receiving)
    {
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('create', GoodsReceiptNote::class);

        $grn = GoodsReceiptNote::create([
            'tenant_id' => $purchaseOrder->tenant_id,
            'purchase_order_id' => $purchaseOrder->id,
        ]);

        return response()->json($grn, 201);
    }

    public function show(GoodsReceiptNote $goodsReceiptNote)
    {
        $this->authorize('view', $goodsReceiptNote);

        return $goodsReceiptNote->load('lines');
    }

    /**
     * §3.4: "Partial delivery is a many-GRNLine-to-one-PurchaseOrderLine
     * relationship" - this can be called more than once against the same
     * purchase_order_line_id, across different GRNs, each with its own
     * quantity_received.
     */
    public function receiveLine(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $this->authorize('create', GoodsReceiptNote::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'purchase_order_line_id' => ['required', 'integer', Rule::exists('purchase_order_lines', 'id')->where('tenant_id', $tenantId)],
            // A transactional quantity - rejects zero/negative outright (§1.1).
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $line = PurchaseOrderLine::where('tenant_id', $tenantId)->findOrFail($data['purchase_order_line_id']);

        $grnLine = $this->receiving->receiveLine($line, $goodsReceiptNote, (string) $data['quantity']);

        return response()->json($grnLine, 201);
    }

    public function recordQualityCheck(Request $request, GRNLine $grnLine)
    {
        // Reuses the same gate as receiving - QC recording is the same
        // role-scoped workflow, not a distinct permission (§11 doesn't
        // separately name a QC-recording role for GRN the way it does
        // for Warehouse's own StockQuarantine QC disposition).
        $this->authorize('create', GoodsReceiptNote::class);

        $data = $request->validate([
            'result' => ['required', 'in:pass,fail'],
            'disposition' => ['required', 'in:accept,rework,scrap'],
        ]);

        $qc = $this->receiving->recordQualityCheck($grnLine, $data['result'], $data['disposition'], $request->user());

        return response()->json($qc, 201);
    }
}
