<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceAssignment;
use App\Services\ResourceAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceAssignmentController extends Controller
{
    public function __construct(private ResourceAssignmentService $assignments) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ResourceAssignment::class);

        return ResourceAssignment::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request, Resource $resource)
    {
        $this->authorize('create', ResourceAssignment::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'project_id' => ['nullable', 'integer'], // no FK yet - projects-milestones-ui
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            'block_start_date' => ['required', 'date'],
            'block_end_date' => ['required', 'date', 'after_or_equal:block_start_date'],
        ]);

        if (($data['project_id'] ?? null) === null && ($data['sales_order_id'] ?? null) === null) {
            abort(422, 'A ResourceAssignment must reference either a project_id or a sales_order_id.');
        }
        if (($data['project_id'] ?? null) !== null && ($data['sales_order_id'] ?? null) !== null) {
            abort(422, 'A ResourceAssignment cannot reference both project_id and sales_order_id.');
        }

        $assignment = $this->assignments->create(
            $request->user()->tenant, $resource, $data['project_id'] ?? null, $data['sales_order_id'] ?? null,
            $data['block_start_date'], $data['block_end_date'],
        );

        return response()->json($assignment, 201);
    }

    public function endEarly(ResourceAssignment $resourceAssignment)
    {
        $this->authorize('update', $resourceAssignment);

        return $this->assignments->endEarly($resourceAssignment);
    }

    public function extend(Request $request, ResourceAssignment $resourceAssignment)
    {
        $this->authorize('update', $resourceAssignment);

        $data = $request->validate([
            'block_end_date' => ['required', 'date', 'after:today'],
        ]);

        return $this->assignments->extend($resourceAssignment, $data['block_end_date']);
    }

    public function cancel(ResourceAssignment $resourceAssignment)
    {
        $this->authorize('update', $resourceAssignment);

        return $this->assignments->cancel($resourceAssignment);
    }
}
