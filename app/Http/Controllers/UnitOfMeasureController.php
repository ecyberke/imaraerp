<?php

namespace App\Http\Controllers;

use App\Models\UnitOfMeasure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitOfMeasureController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', UnitOfMeasure::class);

        return UnitOfMeasure::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', UnitOfMeasure::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'base_unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)],
            // A conversion factor is a rate - rejects negative outright;
            // zero would make the derived unit worth nothing, also invalid.
            'conversion_factor' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $unit = UnitOfMeasure::create([...$data, 'tenant_id' => $tenantId]);

        return response()->json($unit, 201);
    }

    public function show(UnitOfMeasure $unitOfMeasure)
    {
        $this->authorize('view', $unitOfMeasure);

        return $unitOfMeasure;
    }

    public function update(Request $request, UnitOfMeasure $unitOfMeasure)
    {
        $this->authorize('update', $unitOfMeasure);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20'],
            'name' => ['sometimes', 'string', 'max:255'],
            'base_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)],
            'conversion_factor' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ]);

        $unitOfMeasure->update($data);

        return $unitOfMeasure;
    }

    public function destroy(UnitOfMeasure $unitOfMeasure)
    {
        $this->authorize('delete', $unitOfMeasure);

        $unitOfMeasure->delete();

        return response()->noContent();
    }
}
