<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Party;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\EmploymentContractService;
use App\Services\PayrollRunService;
use App\Services\ProjectService;
use App\Services\StatutoryCalculationService;
use App\Services\StatutoryRemittanceService;
use App\Services\TimesheetService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * hr-payroll branch (execution_plan.md): Employee/EmploymentContract
 * (non-overlap), Timesheet, PayrollRun (generate/approve/disburse, real
 * per-project gross-pay split), FinalSettlement, casual-to-permanent
 * conversion, StatutoryRemittance.
 */
class HrPayrollTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);
    }

    private function headersFor(string $email, string $role): array
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function userFor(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create([...[
            'tenant_id' => $this->tenant->id, 'employee_number' => 'EMP-'.uniqid(), 'name' => 'Jane Mwangi',
            'id_number' => '12345678', 'kra_pin' => 'A001234567Z', 'nssf_number' => 'NSSF1',
            'shif_number' => 'SHIF1', 'employment_type' => 'full_time', 'date_of_hire' => BusinessTime::today()->subYear(),
            'status' => 'active',
        ], ...$overrides]);
    }

    private function makeMonthlyContract(Employee $employee, string $basicSalary, string $startDate): EmploymentContract
    {
        return app(EmploymentContractService::class)->create($employee, [
            'contract_type' => 'full_time', 'pay_frequency' => 'monthly',
            'basic_salary' => $basicSalary, 'start_date' => $startDate,
        ]);
    }

    private function accountBalance(string $accountName): Money
    {
        $accountId = DB::table('chart_of_accounts')->where('tenant_id', $this->tenant->id)->where('name', $accountName)->value('id');
        $debit = (int) DB::table('journal_lines')->where('account_id', $accountId)->sum('debit_cents');
        $credit = (int) DB::table('journal_lines')->where('account_id', $accountId)->sum('credit_cents');

        return Money::fromCents($credit - $debit);
    }

    public function test_paye_bands_are_applied_marginally_with_personal_relief(): void
    {
        // 50,000 gross: 24,000@10% + 8,333@25% + remainder(17,667)@30%
        // = 2,400 + 2,083.25 + 5,300.10 = 9,783.35, less 2,400 relief = 7,383.35
        $result = app(StatutoryCalculationService::class)->calculatePaye($this->tenant, Money::fromMajor('50000'), BusinessTime::today());

        $this->assertSame('2400.00', $result['personal_relief']->toMajor());
        $this->assertSame('7383.35', $result['amount']->toMajor());
    }

    public function test_employment_contract_non_overlap_is_enforced(): void
    {
        $employee = $this->makeEmployee();
        $this->makeMonthlyContract($employee, '50000', '2026-01-01');

        $this->expectException(\DomainException::class);
        $this->makeMonthlyContract($employee, '60000', '2026-01-15');
    }

    public function test_promote_closes_old_contract_and_opens_new_one_the_next_day(): void
    {
        $employee = $this->makeEmployee(['employment_type' => 'casual']);
        $casual = app(EmploymentContractService::class)->create($employee, [
            'contract_type' => 'casual', 'pay_frequency' => 'daily', 'daily_rate' => '1500', 'start_date' => '2026-01-01',
        ]);

        $permanent = app(EmploymentContractService::class)->promote($casual, [
            'contract_type' => 'full_time', 'pay_frequency' => 'monthly', 'basic_salary' => '45000', 'start_date' => '2026-01-16',
        ]);

        $this->assertSame('2026-01-15', $casual->fresh()->end_date->toDateString());
        $this->assertSame('2026-01-16', $permanent->start_date->toDateString());
    }

    public function test_full_payroll_cycle_nets_every_payable_to_zero(): void
    {
        $headers = $this->headersFor('hr@example.com', 'hr_manager');
        $employee = $this->makeEmployee();
        $this->makeMonthlyContract($employee, '100000', '2026-01-01');

        $run = $this->withHeaders($headers)->postJson('/api/payroll-runs', [
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/generate")->assertOk();
        $approved = $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/approve")->assertOk();
        $this->assertSame('approved', $approved->json('status'));

        $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/disburse-net-pay")->assertCreated();

        $payrollRun = PayrollRun::find($run->json('id'));
        $payslip = $payrollRun->payslips()->first();

        // Remit every statutory payable this run created, in full.
        $remittances = app(StatutoryRemittanceService::class);
        $tenant = $this->tenant;
        $pairs = [
            ['KRA-PAYE', $payslip->paye_amount],
            ['NSSF', $payslip->nssf_amount->add($payslip->employer_nssf_amount)],
            ['SHIF', $payslip->shif_amount],
            ['HELB', $payslip->helb_amount],
        ];
        foreach ($pairs as [$authority, $amount]) {
            if ($amount->isZero()) {
                continue;
            }
            $remittance = $remittances->record($tenant, '2026-01-31', $authority, $amount->toMajor());
            $remittances->pay($remittance, "REF-{$authority}");
        }
        // NITA is employer-only, still a real payable created by the run.
        $nitaRemittance = $remittances->record($tenant, '2026-01-31', 'NITA', app(StatutoryCalculationService::class)->calculateNita($tenant, BusinessTime::today())->toMajor());
        $remittances->pay($nitaRemittance, 'REF-NITA');

        $this->assertSame('0.00', $this->accountBalance('PAYE Payable')->toMajor());
        $this->assertSame('0.00', $this->accountBalance('NSSF Payable')->toMajor());
        $this->assertSame('0.00', $this->accountBalance('SHIF Payable')->toMajor());
        $this->assertSame('0.00', $this->accountBalance('NITA Payable')->toMajor());
        $this->assertSame('0.00', $this->accountBalance('Net Pay Payable')->toMajor());
    }

    public function test_payroll_run_splits_gross_pay_across_two_projects_an_employee_worked_on(): void
    {
        $headers = $this->headersFor('hr@example.com', 'hr_manager');
        $hrUser = $this->userFor('hr3@example.com', 'hr_manager');
        $projectA = app(ProjectService::class)->create($this->tenant, ['party_id' => $this->party->id, 'name' => 'Site A']);
        $projectB = app(ProjectService::class)->create($this->tenant, ['party_id' => $this->party->id, 'name' => 'Site B']);

        $employee = $this->makeEmployee(['employment_type' => 'casual']);
        app(EmploymentContractService::class)->create($employee, [
            'contract_type' => 'casual', 'pay_frequency' => 'daily', 'daily_rate' => '2000', 'start_date' => '2026-02-01',
        ]);

        $timesheetService = app(TimesheetService::class);
        $t1 = $timesheetService->submit($employee, ['date' => '2026-02-03', 'hours_normal' => 8, 'project_id' => $projectA->id]);
        $t2 = $timesheetService->submit($employee, ['date' => '2026-02-04', 'hours_normal' => 8, 'project_id' => $projectB->id]);
        $timesheetService->approve($t1, $hrUser);
        $timesheetService->approve($t2, $hrUser);

        $run = $this->withHeaders($headers)->postJson('/api/payroll-runs', [
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/generate")->assertOk();
        $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/approve")->assertOk();

        $payrollRun = PayrollRun::find($run->json('id'));
        $entry = $payrollRun->journalEntry;
        $salaryLines = DB::table('journal_lines')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_lines.journal_entry_id', $entry->id)
            ->where('chart_of_accounts.name', 'Salary/Wages Expense')
            ->get();

        // One line per project worked (gross pay = 2 days x 2,000 = 4,000,
        // split 50/50 since equal hours on each project).
        $this->assertCount(2, $salaryLines);
        $this->assertEqualsWithDelta(2000.0, $salaryLines->sum('debit_cents') / 100 / 2, 0.01);
        $this->assertNotNull($salaryLines[0]->analytic_account_id);
        $this->assertNotNull($salaryLines[1]->analytic_account_id);
        $this->assertNotEquals($salaryLines[0]->analytic_account_id, $salaryLines[1]->analytic_account_id);
    }

    public function test_casual_to_permanent_conversion_mid_period_produces_two_payslips(): void
    {
        $headers = $this->headersFor('hr@example.com', 'hr_manager');
        $employee = $this->makeEmployee(['employment_type' => 'casual']);
        $casual = app(EmploymentContractService::class)->create($employee, [
            'contract_type' => 'casual', 'pay_frequency' => 'daily', 'daily_rate' => '1500', 'start_date' => '2026-03-01',
        ]);
        app(EmploymentContractService::class)->promote($casual, [
            'contract_type' => 'full_time', 'pay_frequency' => 'monthly', 'basic_salary' => '45000', 'start_date' => '2026-03-16',
        ]);

        $run = $this->withHeaders($headers)->postJson('/api/payroll-runs', [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ])->assertCreated();
        $generated = $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/generate")->assertOk();

        $payslips = collect($generated->json('payslips'))->where('employee_id', $employee->id)->sortBy('period_start')->values();
        $this->assertCount(2, $payslips);
        $this->assertSame('2026-03-01', substr($payslips->first()['period_start'], 0, 10));
        $this->assertSame('2026-03-15', substr($payslips->first()['period_end'], 0, 10));
        $this->assertSame('2026-03-16', substr($payslips->last()['period_start'], 0, 10));
        $this->assertSame('2026-03-31', substr($payslips->last()['period_end'], 0, 10));
    }

    public function test_mid_month_termination_produces_a_prorated_final_settlement(): void
    {
        $headers = $this->headersFor('hr@example.com', 'hr_manager');
        $employee = $this->makeEmployee();
        $this->makeMonthlyContract($employee, '62000', '2026-01-01');

        $run = app(PayrollRunService::class)->createRun($this->tenant, '2026-04-01', '2026-04-30');

        // 15 of 30 days worked = half the monthly salary before deductions.
        $settlement = $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->id}/settle-employee", [
            'employee_id' => $employee->id, 'last_working_day' => '2026-04-15', 'encash_leave' => false,
        ])->assertCreated();

        $this->assertSame('15.00', (string) $settlement->json('days_worked_this_period'));
        $this->assertSame('terminated', $employee->fresh()->status);
        $this->assertSame('2026-04-15', $employee->fresh()->date_of_exit->toDateString());

        $payslip = $employee->payslips()->first();
        $this->assertSame('31000.00', $payslip->gross_pay->toMajor());
    }

    public function test_timesheet_approval_tracks_casual_days_and_flags_conversion_due(): void
    {
        $hrUser = $this->userFor('hr4@example.com', 'hr_manager');
        $employee = $this->makeEmployee(['employment_type' => 'casual']);
        $timesheetService = app(TimesheetService::class);

        for ($i = 0; $i < 90; $i++) {
            $ts = $timesheetService->submit($employee, ['date' => BusinessTime::today()->subDays(90 - $i)->toDateString(), 'hours_normal' => 8]);
            $timesheetService->approve($ts, $hrUser);
        }

        $fresh = $employee->fresh();
        $this->assertSame(90, $fresh->cumulative_casual_days_worked);
        $this->assertTrue($fresh->casual_conversion_due);
    }

    public function test_p9a_and_p10_are_generated_from_payslips(): void
    {
        $headers = $this->headersFor('hr@example.com', 'hr_manager');
        $employee = $this->makeEmployee();
        $this->makeMonthlyContract($employee, '80000', '2026-05-01');

        $run = $this->withHeaders($headers)->postJson('/api/payroll-runs', [
            'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/generate")->assertOk();
        $this->withHeaders($headers)->postJson("/api/payroll-runs/{$run->json('id')}/approve")->assertOk();

        $p9a = $this->withHeaders($headers)->getJson("/api/employees/{$employee->id}/p9a?year=2026")->assertOk();
        $this->assertSame('80000.00', $p9a->json('taxable_pay'));

        $p10 = $this->withHeaders($headers)->getJson('/api/p10?month=5&year=2026')->assertOk();
        $this->assertSame(1, $p10->json('employee_count'));
    }

    public function test_employee_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('hr@example.com', 'hr_manager');
        $employee = $this->makeEmployee();

        $this->app['auth']->forgetGuards();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'hr_manager')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other HR', 'email' => 'other-hr@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => false,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $this->withHeaders($otherHeaders)->getJson("/api/employees/{$employee->id}")->assertStatus(404);
    }
}
