<?php

namespace App\Http\Controllers;

use App\Models\CreditApproval;
use App\Models\Invoice;
use App\Models\User;
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

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'notes' => ['required', 'string'],
            'second_approver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        /** @var User $approver */
        $approver = $request->user();
        $secondApprover = isset($data['second_approver_id']) ? User::where('tenant_id', $tenantId)->find($data['second_approver_id']) : null;

        $approval = $this->creditApprovals->override($creditApproval, $approver, $data['notes'], $secondApprover);

        return response()->json($approval);
    }
}
