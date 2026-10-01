<?php

namespace App\Http\Controllers;

use App\Models\Boq;
use App\Models\BoqImportStaging;
use App\Services\BoqImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoqImportStagingController extends Controller
{
    public function __construct(private BoqImportService $imports) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', BoqImportStaging::class);

        return BoqImportStaging::where('tenant_id', $request->user()->tenant_id)->get();
    }

    /**
     * §3.8: lands rows in staging exactly as extracted from the source
     * file - the caller (a UI-side parser, in phase1-screens) hands over
     * already-extracted rows rather than this endpoint parsing a file
     * itself; see BoqImportService's docblock for why.
     */
    public function store(Request $request)
    {
        $this->authorize('create', BoqImportStaging::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'boq_id' => ['nullable', 'integer', Rule::exists('boqs', 'id')->where('tenant_id', $tenantId)],
            'source_file_path' => ['nullable', 'string', 'max:500'],
            'rows' => ['required', 'array', 'min:1'],
        ]);

        $staged = $this->imports->stage(
            $request->user()->tenant, $data['rows'], $data['source_file_path'] ?? null, null, $data['boq_id'] ?? null,
        );

        return response()->json($staged, 201);
    }

    public function map(Request $request, BoqImportStaging $boqImportStaging)
    {
        $this->authorize('update', $boqImportStaging);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'section' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'rate' => ['required', 'numeric', 'min:0'],
        ]);

        $staging = $this->imports->map(
            $boqImportStaging, $data['item_id'] ?? null, $data['section'] ?? null, $data['quantity'], $data['rate'],
        );

        return response()->json($staging);
    }

    public function confirm(Request $request, BoqImportStaging $boqImportStaging)
    {
        $this->authorize('update', $boqImportStaging);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'boq_id' => ['required', 'integer', Rule::exists('boqs', 'id')->where('tenant_id', $tenantId)],
        ]);

        $boq = Boq::where('tenant_id', $tenantId)->findOrFail($data['boq_id']);

        $line = $this->imports->confirm($boqImportStaging, $boq, $request->user());

        return response()->json($line, 201);
    }

    public function reject(Request $request, BoqImportStaging $boqImportStaging)
    {
        $this->authorize('update', $boqImportStaging);

        return $this->imports->reject($boqImportStaging, $request->user());
    }
}
