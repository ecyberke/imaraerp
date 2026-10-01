<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveRequestService $leaveRequests) {}

    public function indexForEmployee(Employee $employee)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        return LeaveRequest::where('employee_id', $employee->id)->orderByDesc('start_date')->get();
    }

    public function store(Request $request, Employee $employee)
    {
        $this->authorize('create', LeaveRequest::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        return response()->json($this->leaveRequests->create($employee, $data), 201);
    }

    public function approve(LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        return response()->json($this->leaveRequests->approve($leaveRequest));
    }

    public function reject(LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        return response()->json($this->leaveRequests->reject($leaveRequest));
    }
}
