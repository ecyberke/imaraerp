<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Project;
use App\Services\AssetAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetAssignmentController extends Controller
{
    public function __construct(private AssetAssignmentService $assignments) {}

    public function indexForAsset(Asset $asset)
    {
        $this->authorize('viewAny', AssetAssignment::class);

        return AssetAssignment::where('asset_id', $asset->id)->get();
    }

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('create', AssetAssignment::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
            'assigned_date' => ['required', 'date'],
            'internal_daily_rate' => ['required', 'numeric', 'gt:0'],
            'meter_reading_start' => ['nullable', 'numeric'],
        ]);

        $project = Project::where('tenant_id', $tenantId)->findOrFail($data['project_id']);

        $assignment = $this->assignments->assign(
            $asset,
            $project,
            $data['assigned_date'],
            (string) $data['internal_daily_rate'],
            isset($data['meter_reading_start']) ? (string) $data['meter_reading_start'] : null,
        );

        return response()->json($assignment, 201);
    }

    public function release(Request $request, AssetAssignment $assetAssignment)
    {
        $this->authorize('update', $assetAssignment);

        $data = $request->validate([
            'released_date' => ['required', 'date'],
            'meter_reading_end' => ['nullable', 'numeric'],
        ]);

        $assignment = $this->assignments->release(
            $assetAssignment,
            $data['released_date'],
            isset($data['meter_reading_end']) ? (string) $data['meter_reading_end'] : null,
        );

        return response()->json($assignment);
    }

    public function postCharge(Request $request, AssetAssignment $assetAssignment)
    {
        $this->authorize('update', $assetAssignment);

        $data = $request->validate(['days' => ['required', 'integer', 'min:1']]);

        $journalEntry = $this->assignments->postPeriodicCharge($assetAssignment, $data['days']);

        return response()->json($journalEntry, 201);
    }
}
