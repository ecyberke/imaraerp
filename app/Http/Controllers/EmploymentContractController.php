<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Services\EmploymentContractService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmploymentContractController extends Controller
{
    public function __construct(private EmploymentContractService $contracts) {}

    public function indexForEmployee(Employee $employee)
    {
        $this->authorize('viewAny', EmploymentContract::class);

        return EmploymentContract::where('employee_id', $employee->id)->orderBy('start_date')->get();
    }

    private function rules(): array
    {
        return [
            'contract_type' => ['required', 'string', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'pay_frequency' => ['required', 'string', Rule::in(EmploymentContract::PAY_FREQUENCIES)],
            'basic_salary' => ['nullable', 'numeric', 'gt:0'],
            'hourly_rate' => ['nullable', 'numeric', 'gt:0'],
            'daily_rate' => ['nullable', 'numeric', 'gt:0'],
            'overtime_multiplier_weekday' => ['nullable', 'numeric', 'gt:0'],
            'overtime_multiplier_restday' => ['nullable', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function store(Request $request, Employee $employee)
    {
        $this->authorize('create', EmploymentContract::class);

        $data = $request->validate($this->rules());

        return response()->json($this->contracts->create($employee, $data), 201);
    }

    public function promote(Request $request, EmploymentContract $employmentContract)
    {
        $this->authorize('update', $employmentContract);

        $data = $request->validate($this->rules());

        return response()->json($this->contracts->promote($employmentContract, $data), 201);
    }
}
