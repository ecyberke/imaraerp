<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\VariationOrder;
use App\Models\VariationOrderLine;
use App\Services\VariationOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VariationOrderController extends Controller
{
    public function __construct(private VariationOrderService $variationOrders) {}

    public function indexForProject(Request $request, Project $project)
    {
        $this->authorize('viewAny', VariationOrder::class);

        return VariationOrder::where('project_id', $project->id)->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('create', VariationOrder::class);

        $data = $request->validate(['description' => ['required', 'string', 'max:1000']]);

        return response()->json($this->variationOrders->create($project, $data['description']), 201);
    }

    public function show(VariationOrder $variationOrder)
    {
        $this->authorize('view', $variationOrder);

        return $variationOrder->load('lines', 'reversal.lines', 'approvedBy', 'secondApprovedBy');
    }

    public function addLine(Request $request, VariationOrder $variationOrder)
    {
        $this->authorize('update', $variationOrder);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'boq_line_id' => ['nullable', 'integer', Rule::exists('boq_lines', 'id')->where('tenant_id', $tenantId)],
            'section_id' => ['nullable', 'integer', Rule::exists('boq_sections', 'id')->where('tenant_id', $tenantId)],
            'variation_type' => ['required', 'string', Rule::in(VariationOrderLine::VARIATION_TYPES)],
            'quantity_delta' => ['nullable', 'numeric'],
            'rate_delta' => ['nullable', 'numeric'],
            'amount_delta' => ['required', 'numeric'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($this->variationOrders->addLine($variationOrder, $data), 201);
    }

    public function submit(VariationOrder $variationOrder)
    {
        $this->authorize('update', $variationOrder);

        return response()->json($this->variationOrders->submit($variationOrder));
    }

    public function reject(VariationOrder $variationOrder)
    {
        $this->authorize('update', $variationOrder);

        return response()->json($this->variationOrders->reject($variationOrder));
    }

    public function approve(Request $request, VariationOrder $variationOrder)
    {
        $this->authorize('update', $variationOrder);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'second_approver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        /** @var User $approver */
        $approver = $request->user();
        $secondApprover = isset($data['second_approver_id']) ? User::where('tenant_id', $tenantId)->find($data['second_approver_id']) : null;

        return response()->json($this->variationOrders->approve($variationOrder, $approver, $secondApprover));
    }

    public function execute(VariationOrder $variationOrder)
    {
        $this->authorize('update', $variationOrder);

        return response()->json($this->variationOrders->execute($variationOrder));
    }

    public function reverse(Request $request, VariationOrder $variationOrder)
    {
        $this->authorize('update', $variationOrder);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->variationOrders->reverse($variationOrder, $data['reason'], $user), 201);
    }
}
