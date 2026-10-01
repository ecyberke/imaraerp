<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\FinalSettlementService;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollRunController extends Controller
{
    public function __construct(
        private PayrollRunService $payrollRuns,
        private FinalSettlementService $finalSettlements,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PayrollRun::class);

        return PayrollRun::where('tenant_id', $request->user()->tenant_id)->orderByDesc('period_start')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', PayrollRun::class);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        return response()->json($this->payrollRuns->createRun($request->user()->tenant, $data['period_start'], $data['period_end']), 201);
    }

    public function show(PayrollRun $payrollRun)
    {
        $this->authorize('view', $payrollRun);

        return $payrollRun->load('payslips.employee');
    }

    public function generate(PayrollRun $payrollRun)
    {
        $this->authorize('update', $payrollRun);

        return response()->json($this->payrollRuns->generate($payrollRun)->load('payslips.employee'));
    }

    public function approve(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize('update', $payrollRun);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->payrollRuns->approve($payrollRun, $user));
    }

    public function disburseNetPay(PayrollRun $payrollRun)
    {
        $this->authorize('update', $payrollRun);

        return response()->json($this->payrollRuns->disburseNetPay($payrollRun), 201);
    }

    public function settleEmployee(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize('update', $payrollRun);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'last_working_day' => ['required', 'date'],
            'encash_leave' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::where('tenant_id', $tenantId)->findOrFail($data['employee_id']);

        $settlement = $this->finalSettlements->settle(
            $employee, $payrollRun, Carbon::parse($data['last_working_day']), $data['encash_leave'] ?? true,
        );

        return response()->json($settlement, 201);
    }
}
