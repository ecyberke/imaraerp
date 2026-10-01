<?php

namespace App\Http\Controllers;

use App\Models\EquipmentHireContract;
use App\Models\Party;
use App\Models\Project;
use App\Services\EquipmentHireContractService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentHireContractController extends Controller
{
    public function __construct(private EquipmentHireContractService $contracts) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', EquipmentHireContract::class);

        return EquipmentHireContract::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', EquipmentHireContract::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
            'description' => ['required', 'string', 'max:255'],
            'hire_rate' => ['required', 'numeric', 'gt:0'],
            'hire_start_date' => ['required', 'date'],
            'hire_end_date' => ['nullable', 'date', 'after_or_equal:hire_start_date'],
        ]);

        $party = Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']);
        $project = Project::where('tenant_id', $tenantId)->findOrFail($data['project_id']);

        $contract = $this->contracts->create($party, $project, $data);

        return response()->json($contract, 201);
    }

    public function recordInvoicedDays(Request $request, EquipmentHireContract $equipmentHireContract)
    {
        $this->authorize('update', $equipmentHireContract);

        $data = $request->validate(['invoiced_days' => ['required', 'integer', 'min:1']]);

        return response()->json($this->contracts->recordInvoicedDays($equipmentHireContract, $data['invoiced_days']));
    }
}
