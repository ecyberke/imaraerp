<?php

namespace App\Http\Controllers;

use App\Models\BoqLine;
use App\Models\MeasurementSheet;
use Illuminate\Http\Request;

/**
 * §3.8's MeasurementSheet was modeled in crm-sales-boq but never got a
 * controller/route - phase1-screens' "Bill from BOQ" UX needs a real
 * certify action to bill against certified quantities rather than a
 * BoqLine's raw (uncertified) quantity, so this closes that gap now
 * rather than silently billing off the wrong figure.
 */
class MeasurementSheetController extends Controller
{
    public function store(Request $request, BoqLine $boqLine)
    {
        $this->authorize('create', MeasurementSheet::class);

        $data = $request->validate([
            'period' => ['required', 'string', 'max:50'],
            'previous_qty' => ['nullable', 'numeric', 'min:0'],
            'current_qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $previousQty = $data['previous_qty'] ?? 0;

        $sheet = MeasurementSheet::create([
            'tenant_id' => $boqLine->tenant_id,
            'boq_line_id' => $boqLine->id,
            'period' => $data['period'],
            'previous_qty' => $previousQty,
            'current_qty' => $data['current_qty'],
            'cumulative_qty' => $previousQty + $data['current_qty'],
            'status' => 'submitted',
        ]);

        return response()->json($sheet, 201);
    }

    public function certify(Request $request, MeasurementSheet $measurementSheet)
    {
        $this->authorize('update', $measurementSheet);

        if ($measurementSheet->status === 'certified') {
            abort(422, 'This measurement sheet is already certified.');
        }

        $data = $request->validate([
            'certified_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $measurementSheet->update([
            'certified_qty' => $data['certified_qty'] ?? $measurementSheet->cumulative_qty,
            'status' => 'certified',
        ]);

        return response()->json($measurementSheet->fresh());
    }

    public function indexForLine(BoqLine $boqLine)
    {
        $this->authorize('viewAny', MeasurementSheet::class);

        return $boqLine->measurementSheets()->orderBy('id')->get();
    }
}
