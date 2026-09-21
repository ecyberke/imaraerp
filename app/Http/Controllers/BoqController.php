<?php

namespace App\Http\Controllers;

use App\Models\Boq;
use App\Models\Subcontract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoqController extends Controller
{
    /**
     * boqable_type accepts short aliases ('subcontract', 'project') and
     * is stored as the FQCN Eloquent's default (unmapped) morphTo
     * resolution expects. 'project' has no existence check against a
     * `projects` table - Project doesn't exist yet (projects-milestones-ui)
     * - same forward-reference pattern used everywhere else in this
     * codebase, just expressed through a polymorphic column instead of a
     * plain nullable FK.
     */
    private const BOQABLE_TYPES = [
        'subcontract' => Subcontract::class,
        'project' => 'App\\Models\\Project',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Boq::class);

        return Boq::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Boq::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'boqable_type' => ['required', 'string', Rule::in(array_keys(self::BOQABLE_TYPES))],
            'boqable_id' => ['required', 'integer'],
            'uses_sections' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', Rule::in(['manual', 'uploaded', 'generated'])],
            'source_file_path' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['boqable_type'] === 'subcontract') {
            $request->validate([
                'boqable_id' => [Rule::exists('subcontracts', 'id')->where('tenant_id', $tenantId)],
            ]);
        }

        $boq = Boq::create([
            'tenant_id' => $tenantId,
            'boqable_type' => self::BOQABLE_TYPES[$data['boqable_type']],
            'boqable_id' => $data['boqable_id'],
            'uses_sections' => $data['uses_sections'] ?? false,
            'source' => $data['source'] ?? 'manual',
            'source_file_path' => $data['source_file_path'] ?? null,
            'status' => 'draft',
        ]);

        return response()->json($boq, 201);
    }

    public function show(Boq $boq)
    {
        $this->authorize('view', $boq);

        return $boq->load('sections', 'lines', 'markups');
    }
}
