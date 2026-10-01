<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * §3.11: "Seed data, stated as a required Phase 1 deliverable: the
 * current and next calendar year's Kenyan public holidays must be seeded
 * before the first payroll run." Only the fixed-date gazetted holidays
 * are seeded here - Good Friday/Easter Monday and the Eid holidays are
 * movable (paschal/lunar calendar), and guessing their dates wrong would
 * silently miscalculate daily-rate pay for whoever's rostered that day;
 * flagged rather than hardcoded, left for HR to add once the government
 * gazettes each year's actual date.
 */
final class PublicHolidaySeeder
{
    private const FIXED_DATE_HOLIDAYS = [
        ['month' => 1, 'day' => 1, 'name' => "New Year's Day"],
        ['month' => 5, 'day' => 1, 'name' => 'Labour Day'],
        ['month' => 6, 'day' => 1, 'name' => 'Madaraka Day'],
        ['month' => 10, 'day' => 20, 'name' => 'Mashujaa Day'],
        ['month' => 12, 'day' => 12, 'name' => 'Jamhuri Day'],
        ['month' => 12, 'day' => 25, 'name' => 'Christmas Day'],
        ['month' => 12, 'day' => 26, 'name' => 'Boxing Day'],
    ];

    public static function seed(Tenant $tenant): void
    {
        $now = now();
        $thisYear = BusinessTime::today()->year;

        $rows = [];
        foreach ([$thisYear, $thisYear + 1] as $year) {
            foreach (self::FIXED_DATE_HOLIDAYS as $holiday) {
                $rows[] = [
                    'tenant_id' => $tenant->getKey(),
                    'date' => sprintf('%04d-%02d-%02d', $year, $holiday['month'], $holiday['day']),
                    'name' => $holiday['name'],
                    'is_paid' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('public_holidays')->insert($rows);
    }
}
