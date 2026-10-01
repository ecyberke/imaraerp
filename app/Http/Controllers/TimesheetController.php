<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TimesheetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimesheetController extends Controller
{
    public function __construct(private TimesheetService $timesheets) {}

    public function indexForEmployee(Employee $employee)
    {
        $this->authorize('viewAny', Timesheet::class);

        return Timesheet::where('employee_id', $employee->id)->orderByDesc('date')->get();
    }

    public function store(Request $request, Employee $employee)
    {
        $this->authorize('create', Timesheet::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'date' => ['required', 'date'],
            'hours_normal' => ['required', 'numeric', 'gte:0'],
            'hours_overtime_weekday' => ['nullable', 'numeric', 'gte:0'],
            'hours_overtime_restday' => ['nullable', 'numeric', 'gte:0'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
        ]);

        return response()->json($this->timesheets->submit($employee, $data), 201);
    }

    public function approve(Request $request, Timesheet $timesheet)
    {
        $this->authorize('update', $timesheet);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->timesheets->approve($timesheet, $user));
    }

    public function reject(Timesheet $timesheet)
    {
        $this->authorize('update', $timesheet);

        return response()->json($this->timesheets->reject($timesheet));
    }
}
