<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $projects) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        return Project::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'specification_file_path' => ['nullable', 'string', 'max:500'],
        ]);

        $project = $this->projects->create($request->user()->tenant, $data);

        return response()->json($project, 201);
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return $project->load('party', 'milestones', 'variationOrders', 'defects', 'boq.sections', 'boq.lines');
    }

    public function completionPercentage(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json(['completion_percentage' => $this->projects->completionPercentage($project)]);
    }

    public function start(Project $project)
    {
        $this->authorize('update', $project);

        return response()->json($this->projects->start($project));
    }

    public function markComplete(Project $project)
    {
        $this->authorize('update', $project);

        return response()->json($this->projects->markComplete($project));
    }

    public function close(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate(['version' => ['required', 'integer']]);

        return response()->json($this->projects->closeAfterDefectsLiability($project, $data['version']));
    }

    public function cancel(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->projects->cancel($project, $data['reason'], $user));
    }
}
