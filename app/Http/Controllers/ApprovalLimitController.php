<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLimit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalLimitController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ApprovalLimit::class);

        return ApprovalLimit::where('tenant_id', $request->user()->tenant_id)->with('role', 'secondApproverRole')->get();
    }

    private function rules(int $tenantId): array
    {
        return [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'entity_type' => ['required', 'string', Rule::in(ApprovalLimit::ENTITY_TYPES)],
            'max_amount' => ['required', 'numeric', 'gt:0'],
            'requires_second_approval_above' => ['nullable', 'numeric', 'gt:0'],
            'second_approver_role_id' => [
                'nullable', 'integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId),
                'required_with:requires_second_approval_above',
            ],
        ];
    }

    public function store(Request $request)
    {
        $this->authorize('create', ApprovalLimit::class);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate($this->rules($tenantId));

        $limit = ApprovalLimit::updateOrCreate(
            ['tenant_id' => $tenantId, 'role_id' => $data['role_id'], 'entity_type' => $data['entity_type']],
            [
                'max_amount_cents' => Money::fromMajor($data['max_amount']),
                'requires_second_approval_above_cents' => isset($data['requires_second_approval_above']) ? Money::fromMajor($data['requires_second_approval_above']) : null,
                'second_approver_role_id' => $data['second_approver_role_id'] ?? null,
            ],
        );

        return response()->json($limit, 201);
    }

    public function update(Request $request, ApprovalLimit $approvalLimit)
    {
        $this->authorize('update', $approvalLimit);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate($this->rules($tenantId));

        $approvalLimit->update([
            'role_id' => $data['role_id'],
            'entity_type' => $data['entity_type'],
            'max_amount_cents' => Money::fromMajor($data['max_amount']),
            'requires_second_approval_above_cents' => isset($data['requires_second_approval_above']) ? Money::fromMajor($data['requires_second_approval_above']) : null,
            'second_approver_role_id' => $data['second_approver_role_id'] ?? null,
        ]);

        return response()->json($approvalLimit->fresh());
    }
}
