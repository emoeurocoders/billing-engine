<?php

namespace Omni\BillingEngine\Support;

use Illuminate\Support\Carbon;

/**
 * Single source of "now" for the engine, in the configured billing timezone
 * (config('billing-engine.timezone'), e.g. MST7MDT). EVERY scheduling decision —
 * due dates written to the schedule, the dispatcher's "is it due?" claim, and the
 * guards' cycle/same-day windows — MUST read the clock through here so they all
 * agree on the same calendar day.
 *
 * Why this exists: the app runs in UTC (config/app.php), but the ledger dates and
 * the legacy crons are MST. When the scheduler used bare Carbon::now() (UTC) while
 * the guards used Carbon::now('MST7MDT'), a job that ran in the early UTC hours
 * (00:00–05:59 UTC = the PREVIOUS day in MST) evaluated the guards a day behind the
 * scheduler. That slid the conversion_rebill look-back window back onto the member's
 * own anchor charge and made same_day match a prior-day transaction — silently
 * deferring on-schedule rebills a full cycle. Routing all "now" through one
 * configured timezone removes the split-brain.
 *
 * Mirrors the mid-balancer package, which threads config('mid-balancer.timezone')
 * through every Carbon::now($tz) call rather than mutating PHP's global timezone.
 */
final class Clock
{
    /** The configured billing timezone. Falls back to UTC only if unset. */
    public static function tz(): string
    {
        return (string) config('billing-engine.timezone', 'UTC');
    }

    /** "now" in the billing timezone — use everywhere instead of Carbon::now(). */
    public static function now(): Carbon
    {
        return Carbon::now(self::tz());
    }

    /** Today's date (Y-m-d) in the billing timezone. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /**
     * Parse a value in the billing timezone (so a naive ledger datetime — stored
     * in MST — is anchored to the SAME zone the rest of the engine schedules in,
     * never re-interpreted as the UTC app default).
     */
    public static function parse($value): Carbon
    {
        return Carbon::parse($value, self::tz());
    }
}
