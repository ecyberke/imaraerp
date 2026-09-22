<?php

namespace App\Http\Controllers;

use App\Models\ContractRetentionTerms;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractRetentionTermsController extends Controller
{
    private const CONTRACT_TYPES = [
        'sales_order' => SalesOrder::class,
        'subcontract' => Subcontract::class,
    ];

    public function store(Request $request)
    {
        $this->authorize('create', ContractRetentionTerms::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'contract_type' => ['required', 'string', Rule::in(array_keys(self::CONTRACT_TYPES))],
            'contract_id' => ['required', 'integer'],
            'direction' => ['required', 'string', Rule::in(['receivable', 'payable'])],
            'retention_percentage' => ['required', 'numeric', 'min:0', 'max:1'],
            'retention_cap' => ['nullable', 'numeric', 'min:0'],
            'first_release_trigger' => ['nullable', 'string'],
            'second_release_trigger' => ['nullable', 'string'],
            'dlp_duration_months' => ['nullable', 'integer', 'min:0'],
            'release_trigger_source' => ['nullable', 'string', Rule::in(['own_dlp', 'main_contract_dlp'])],
        ]);

        if ($data['contract_type'] === 'subcontract') {
            $request->validate([
                'contract_id' => [Rule::exists('subcontracts', 'id')->where('tenant_id', $tenantId)],
            ]);
        } else {
            $request->validate([
                'contract_id' => [Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            ]);
        }

        $terms = ContractRetentionTerms::create([
            'tenant_id' => $tenantId,
            'contract_type' => self::CONTRACT_TYPES[$data['contract_type']],
            'contract_id' => $data['contract_id'],
            'direction' => $data['direction'],
            'retention_percentage' => $data['retention_percentage'],
            'retention_cap_cents' => isset($data['retention_cap']) ? \App\Support\Money::fromMajor($data['retention_cap']) : null,
            'first_release_trigger' => $data['first_release_trigger'] ?? 'practical_completion',
            'second_release_trigger' => $data['second_release_trigger'] ?? 'dlp_end',
            'dlp_duration_months' => $data['dlp_duration_months'] ?? null,
            'release_trigger_source' => $data['release_trigger_source'] ?? null,
        ]);

        return response()->json($terms, 201);
    }
}
