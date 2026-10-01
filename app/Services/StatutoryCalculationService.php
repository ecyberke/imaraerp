<?php

namespace App\Services;

use App\Models\StatutoryDeductionRate;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * §3.11: "kept as data, not hardcoded logic, since these change on
 * government policy, not on a release cycle." Reads StatutoryDeductionRate
 * (StatutoryDeductionRateSeeder has the current rates and their shapes)
 * rather than computing against any rate baked into this class.
 */
class StatutoryCalculationService
{
    /** @return array{amount: Money, personal_relief: Money} */
    public function calculatePaye(Tenant $tenant, Money $taxablePay, CarbonInterface $date): array
    {
        $rate = $this->currentRate($tenant, 'paye', $date);
        $grossTax = $this->applyBands($taxablePay, $rate->rate_bands['bands']);
        $relief = Money::fromCents($rate->rate_bands['personal_relief_cents'] ?? 0);
        $net = $grossTax->sub($relief);

        return ['amount' => $net->isPositive() ? $net : Money::zero(), 'personal_relief' => $relief];
    }

    /** @return array{employee: Money, employer: Money} */
    public function calculateNssf(Tenant $tenant, Money $grossPay, CarbonInterface $date): array
    {
        $rate = $this->currentRate($tenant, 'nssf', $date);
        $employee = $this->applyBands($grossPay, $rate->rate_bands['bands']);
        $employer = $rate->employer_rate_bands ? $this->applyBands($grossPay, $rate->employer_rate_bands['bands']) : Money::zero();

        return ['employee' => $employee, 'employer' => $employer];
    }

    /** §7/v9: SHIF has no employer-matched portion under current Kenyan law. */
    public function calculateShif(Tenant $tenant, Money $grossPay, CarbonInterface $date): Money
    {
        return $this->calculateFlatRateEmployeeOnly($tenant, 'shif', $grossPay, $date);
    }

    /** @return array{employee: Money, employer: Money} */
    public function calculateHousingLevy(Tenant $tenant, Money $grossPay, CarbonInterface $date): array
    {
        return $this->calculateFlatRateWithEmployer($tenant, 'housing_levy', $grossPay, $date);
    }

    /** Placeholder schedule - see StatutoryDeductionRateSeeder's own docblock. */
    public function calculateHelb(Tenant $tenant, Money $grossPay, CarbonInterface $date): Money
    {
        return $this->calculateFlatRateEmployeeOnly($tenant, 'helb', $grossPay, $date);
    }

    /** §3.11: "entirely an employer cost, not an employee deduction - it never appears on Payslip as a deduction." */
    public function calculateNita(Tenant $tenant, CarbonInterface $date): Money
    {
        $rate = $this->currentRate($tenant, 'nita', $date);

        return Money::fromCents($rate->rate_bands['flat_amount_cents'] ?? 0);
    }

    private function calculateFlatRateEmployeeOnly(Tenant $tenant, string $type, Money $grossPay, CarbonInterface $date): Money
    {
        $rate = $this->currentRate($tenant, $type, $date);

        return $this->applyFlatRate($grossPay, $rate->rate_bands);
    }

    /** @return array{employee: Money, employer: Money} */
    private function calculateFlatRateWithEmployer(Tenant $tenant, string $type, Money $grossPay, CarbonInterface $date): array
    {
        $rate = $this->currentRate($tenant, $type, $date);
        $employee = $this->applyFlatRate($grossPay, $rate->rate_bands);
        $employer = $rate->employer_rate_bands ? $this->applyFlatRate($grossPay, $rate->employer_rate_bands) : Money::zero();

        return ['employee' => $employee, 'employer' => $employer];
    }

    private function applyFlatRate(Money $grossPay, array $bands): Money
    {
        if (! $grossPay->isPositive()) {
            return Money::zero();
        }

        $amount = $grossPay->multiplyByRate($bands['flat_rate'] ?? 0);
        $minimum = $bands['minimum_cents'] ?? null;

        return $minimum !== null && $amount->cents() < $minimum ? Money::fromCents($minimum) : $amount;
    }

    private function currentRate(Tenant $tenant, string $deductionType, CarbonInterface $date): StatutoryDeductionRate
    {
        $rate = StatutoryDeductionRate::where('tenant_id', $tenant->id)
            ->where('deduction_type', $deductionType)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if (! $rate) {
            throw new \DomainException("No StatutoryDeductionRate seeded for '{$deductionType}' effective on or before {$date->toDateString()}.");
        }

        return $rate;
    }

    /**
     * §3.11: PAYE is marginal-banded; NSSF's Tier I/II are each a flat
     * rate applied to their own slice of pensionable pay - the same
     * cumulative-threshold banding mechanism, just with different numbers,
     * so both reuse this one method rather than two near-duplicate ones.
     * up_to is a cumulative threshold in cents (null = no cap, the top
     * band), not a per-band width.
     */
    private function applyBands(Money $amount, array $bands): Money
    {
        $remainingCents = $amount->cents();
        $previousCap = 0;
        $tax = Money::zero();

        foreach ($bands as $band) {
            if ($remainingCents <= 0) {
                break;
            }

            $cap = $band['up_to'] ?? null;
            $bandCapacity = $cap === null ? $remainingCents : max(0, $cap - $previousCap);
            $bandAmount = min($remainingCents, $bandCapacity);

            if ($bandAmount > 0) {
                $tax = $tax->add(Money::fromCents($bandAmount)->multiplyByRate($band['rate']));
                $remainingCents -= $bandAmount;
            }

            $previousCap = $cap ?? $previousCap;
        }

        return $tax;
    }
}
