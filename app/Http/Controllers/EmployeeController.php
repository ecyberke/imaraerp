<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employees) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        return Employee::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Employee::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:255', Rule::unique('employees', 'employee_number')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'id_number' => ['required', 'string', 'max:255'],
            'kra_pin' => ['required', 'string', 'max:255'],
            'nssf_number' => ['required', 'string', 'max:255'],
            'shif_number' => ['required', 'string', 'max:255'],
            'helb_account_number' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', 'string', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'date_of_hire' => ['required', 'date'],
        ]);

        return response()->json($this->employees->create($request->user()->tenant, $data), 201);
    }

    public function show(Employee $employee)
    {
        $this->authorize('view', $employee);

        return $employee->load('contracts', 'timesheets', 'leaveRequests');
    }

    public function updateStatus(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $data = $request->validate(['status' => ['required', 'string', Rule::in(Employee::STATUSES)]]);

        return response()->json($this->employees->updateStatus($employee, $data['status']));
    }
}
