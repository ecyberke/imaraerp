<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        return PurchaseOrder::where('tenant_id', $request->user()->tenant_id)->with('lines')->get();
    }

    /**
     * Full ApprovalLimit-gated approval chain (§3.10) is a later branch
     * (approval-notification-compliance, Phase 2) - a PO is created
     * directly in 'ordered' status here, skipping the intermediate
     * pending_approval/approved states this branch doesn't build the
     * approval mechanism for yet.
     */
    public function store(Request $request)
    {
        $this->authorize('create', PurchaseOrder::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'purchase_requisition_id' => ['nullable', 'integer', Rule::exists('purchase_requisitions', 'id')->where('tenant_id', $tenantId)],
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('tenant_id', $tenantId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.purchase_requisition_line_id' => ['nullable', 'integer', Rule::exists('purchase_requisition_lines', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $po = DB::transaction(function () use ($data, $tenantId) {
            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'party_id' => $data['party_id'],
                'currency_id' => $data['currency_id'],
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'status' => 'ordered',
            ]);

            foreach ($data['lines'] as $line) {
                $po->lines()->create([
                    'tenant_id' => $tenantId,
                    'purchase_requisition_line_id' => $line['purchase_requisition_line_id'] ?? null,
                    'item_id' => $line['item_id'],
                    'quantity_ordered' => $line['quantity_ordered'],
                    'unit_cost_cents' => Money::fromMajor($line['unit_cost']),
                ]);
            }

            return $po;
        });

        return response()->json($po->load('lines'), 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        return $purchaseOrder->load('lines', 'grns.lines');
    }
}
