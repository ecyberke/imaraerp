<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PublicHoliday;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reuses EmployeePolicy (hr_manager/admin) - plain HR reference data. */
class PublicHolidayController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        return PublicHoliday::where('tenant_id', $request->user()->tenant_id)->orderBy('date')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Employee::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'date' => ['required', 'date', Rule::unique('public_holidays', 'date')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        return response()->json(PublicHoliday::create([...$data, 'tenant_id' => $tenantId]), 201);
    }
}
