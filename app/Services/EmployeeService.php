<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Tenant;

class EmployeeService
{
    public function create(Tenant $tenant, array $data): Employee
    {
        return Employee::create([...$data, 'tenant_id' => $tenant->id, 'status' => $data['status'] ?? 'active']);
    }

    public function updateStatus(Employee $employee, string $status): Employee
    {
        if (! in_array($status, Employee::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown Employee status '{$status}'.");
        }

        $employee->update(['status' => $status]);

        return $employee->fresh();
    }
}
