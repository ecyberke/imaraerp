<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;

class LeaveRequestService
{
    public function create(Employee $employee, array $data): LeaveRequest
    {
        return LeaveRequest::create([
            ...$data,
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function approve(LeaveRequest $leaveRequest): LeaveRequest
    {
        if ($leaveRequest->status !== 'pending') {
            throw new \DomainException("Cannot approve a LeaveRequest with status '{$leaveRequest->status}'.");
        }

        $leaveRequest->update(['status' => 'approved']);

        return $leaveRequest->fresh();
    }

    public function reject(LeaveRequest $leaveRequest): LeaveRequest
    {
        if ($leaveRequest->status !== 'pending') {
            throw new \DomainException("Cannot reject a LeaveRequest with status '{$leaveRequest->status}'.");
        }

        $leaveRequest->update(['status' => 'rejected']);

        return $leaveRequest->fresh();
    }
}
