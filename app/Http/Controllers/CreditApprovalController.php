<?php

namespace App\Http\Controllers;

use App\Models\CreditApproval;
use App\Models\Invoice;
use App\Services\CreditApprovalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditApprovalController extends Controller
{
    public function __construct(private CreditApprovalService $creditApprovals) {}

    public function store(Request $request)
    {
        $this->authorize('create', CreditApproval::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
        ]);

        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($data['invoice_id']);

        $approval = $this->creditApprovals->request($invoice, $request->user());

        return response()->json($approval, 201);
    }

    public function override(Request $request, CreditApproval $creditApproval)
    {
        $this->authorize('update', $creditApproval);

        $data = $request->validate([
            'notes' => ['required', 'string'],
        ]);

        $approval = $this->creditApprovals->override($creditApproval, $request->user(), $data['notes']);

        return response()->json($approval);
    }
}
