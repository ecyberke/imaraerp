<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $purchaseOrders) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        return PurchaseOrder::where('tenant_id', $request->user()->tenant_id)->with('lines')->get();
    }

    /** §5.2/approval-notification-compliance: created in 'requisitioned' - the real approval chain is submit-for-approval/approve/reject/mark-ordered below. */
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

        return response()->json($this->purchaseOrders->create($request->user()->tenant, $data), 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        return $purchaseOrder->load('lines', 'grns.lines');
    }

    public function submitForApproval(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        return response()->json($this->purchaseOrders->submitForApproval($purchaseOrder));
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'second_approver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        /** @var User $approver */
        $approver = $request->user();
        $secondApprover = isset($data['second_approver_id']) ? User::where('tenant_id', $tenantId)->find($data['second_approver_id']) : null;

        return response()->json($this->purchaseOrders->approve($purchaseOrder, $approver, $secondApprover));
    }

    public function reject(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        return response()->json($this->purchaseOrders->reject($purchaseOrder));
    }

    public function markOrdered(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        return response()->json($this->purchaseOrders->markOrdered($purchaseOrder));
    }
}
