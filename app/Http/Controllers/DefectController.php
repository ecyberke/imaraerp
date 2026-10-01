<?php

namespace App\Http\Controllers;

use App\Models\Defect;
use App\Models\Project;
use App\Models\User;
use App\Services\DefectService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DefectController extends Controller
{
    public function __construct(private DefectService $defects) {}

    public function indexForProject(Request $request, Project $project)
    {
        $this->authorize('viewAny', Defect::class);

        return Defect::where('project_id', $project->id)->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('create', Defect::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'subcontract_id' => ['nullable', 'integer', Rule::exists('subcontracts', 'id')->where('tenant_id', $tenantId)],
            'milestone_id' => ['nullable', 'integer', Rule::exists('milestones', 'id')->where('tenant_id', $tenantId)],
            'description' => ['required', 'string', 'max:1000'],
            'severity' => ['required', 'string', Rule::in(Defect::SEVERITIES)],
            'blocks_retention' => ['nullable', 'boolean'],
            'target_fix_date' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->defects->report($project, $data, $user), 201);
    }

    public function show(Defect $defect)
    {
        $this->authorize('view', $defect);

        return $defect;
    }

    public function setBlocksRetention(Request $request, Defect $defect)
    {
        $this->authorize('update', $defect);

        $data = $request->validate(['blocks_retention' => ['required', 'boolean']]);

        return response()->json($this->defects->setBlocksRetention($defect, $data['blocks_retention']));
    }

    public function advance(Request $request, Defect $defect)
    {
        $this->authorize('update', $defect);

        $data = $request->validate(['status' => ['required', 'string', Rule::in(Defect::STATUSES)]]);

        return response()->json($this->defects->advance($defect, $data['status']));
    }
}
