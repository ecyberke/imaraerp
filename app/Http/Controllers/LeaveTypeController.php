<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Http\Request;

/** Reuses EmployeePolicy (hr_manager/admin) - a plain HR reference-data lookup, same pattern as CurrencyPolicy/AssetCategoryPolicy elsewhere. */
class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        return LeaveType::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Employee::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'days_entitled_per_year' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(LeaveType::create([...$data, 'tenant_id' => $request->user()->tenant_id]), 201);
    }
}
