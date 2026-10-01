<?php

namespace App\Http\Controllers;

use App\Models\BoqSection;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\MilestoneService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MilestoneController extends Controller
{
    public function __construct(private MilestoneService $milestones) {}

    public function indexForProject(Request $request, Project $project)
    {
        $this->authorize('viewAny', Milestone::class);

        return Milestone::where('project_id', $project->id)->orderBy('sequence')->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('create', Milestone::class);

        $data = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->milestones->create($project, $data['sequence'], $data['description']), 201);
    }

    public function show(Milestone $milestone)
    {
        $this->authorize('view', $milestone);

        return $milestone->load('boqLineAllocations.boqLine', 'defects');
    }

    public function allocateLine(Request $request, Milestone $milestone)
    {
        $this->authorize('update', $milestone);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'boq_line_id' => ['required', 'integer', Rule::exists('boq_lines', 'id')->where('tenant_id', $tenantId)],
            'percentage_of_value' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json($this->milestones->allocateLine($milestone, $data['boq_line_id'], $data['percentage_of_value']), 201);
    }

    public function allocateSection(Request $request, Milestone $milestone)
    {
        $this->authorize('update', $milestone);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'section_id' => ['required', 'integer', Rule::exists('boq_sections', 'id')->where('tenant_id', $tenantId)],
            'percentage_of_value' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $section = BoqSection::where('tenant_id', $tenantId)->findOrFail($data['section_id']);

        return response()->json($this->milestones->allocateSection($milestone, $section, $data['percentage_of_value']), 201);
    }

    public function markUtilized(Milestone $milestone)
    {
        $this->authorize('signOff', $milestone);

        return response()->json($this->milestones->markUtilized($milestone));
    }

    public function signOff(Milestone $milestone)
    {
        $this->authorize('signOff', $milestone);

        return response()->json($this->milestones->signOff($milestone));
    }

    public function requireRework(Request $request, Milestone $milestone)
    {
        $this->authorize('signOff', $milestone);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json($this->milestones->requireRework($milestone, $data['reason']));
    }

    public function markInvoiced(Milestone $milestone)
    {
        $this->authorize('update', $milestone);

        return response()->json($this->milestones->markInvoiced($milestone));
    }

    public function close(Milestone $milestone)
    {
        $this->authorize('update', $milestone);

        return response()->json($this->milestones->close($milestone));
    }
}
