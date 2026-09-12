<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Architecture §1.1: every timestamp is stored in UTC, but "the business
 * day" - for posting_date, received_at, paid_at, delivered_at, and every
 * other date used to bucket a transaction into a period - is always
 * evaluated in Africa/Nairobi (EAT, UTC+3), never server-local or raw UTC.
 * A payment at 02:00 EAT on 1 July is 1 July for every reporting and
 * period-lock purpose, even though its UTC timestamp says 30 June.
 *
 * Single point of truth for that conversion, so it isn't reimplemented
 * (and drifted) ad hoc in every module that needs to bucket a date.
 */
final class BusinessTime
{
    public const TIMEZONE = 'Africa/Nairobi';

    /** The current instant, expressed in the business timezone. */
    public static function now(): CarbonInterface
    {
        return Carbon::now(self::TIMEZONE);
    }

    /** Today's business-calendar date (EAT), as a date-only Carbon instance. */
    public static function today(): CarbonInterface
    {
        return self::now()->startOfDay();
    }

    /**
     * Convert any timestamp (assumed UTC if it carries no timezone of its
     * own - which is how Eloquent hands back a stored datetime) into the
     * business-calendar date it falls on. This is the actual bucketing
     * operation: a UTC timestamp of 30 June 23:30 becomes 1 July here.
     */
    public static function dateOf(CarbonInterface|string $timestamp): CarbonInterface
    {
        return Carbon::parse($timestamp, 'UTC')->timezone(self::TIMEZONE)->startOfDay();
    }

    /** The Kenyan tax year (resets 1 January, §3.10) a business date falls in. */
    public static function taxYearOf(CarbonInterface $businessDate): int
    {
        return $businessDate->year;
    }
}
