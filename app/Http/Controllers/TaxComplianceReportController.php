<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\TaxComplianceReportService;
use Illuminate\Http\Request;

/** Reuses PayrollRunPolicy for both actions - P9A/P10 are HR Manager's own output, same role gate as the PayrollRun they're derived from. */
class TaxComplianceReportController extends Controller
{
    public function __construct(private TaxComplianceReportService $reports) {}

    public function p9a(Request $request, Employee $employee)
    {
        $this->authorize('viewAny', PayrollRun::class);

        $data = $request->validate(['year' => ['required', 'integer', 'min:2000']]);

        return response()->json($this->reports->generateP9A($employee, $data['year']));
    }

    public function p10(Request $request)
    {
        $this->authorize('viewAny', PayrollRun::class);

        $data = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000'],
        ]);

        return response()->json($this->reports->generateP10($request->user()->tenant, $data['month'], $data['year']));
    }
}
