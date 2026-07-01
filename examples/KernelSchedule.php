<?php

/**
 * EXAMPLE — scheduling the dispatcher in App\Console\Kernel::schedule().
 *
 * Replace the legacy per-method hourly lines:
 *     $schedule->command('nmi:billing rebillCC')->hourlyAt(31)->withoutOverlapping();
 *     $schedule->command('nmi:billing rebillPP')->hourlyAt(38)->withoutOverlapping();
 *     $schedule->command('nmi:billing rebillSettles')->hourlyAt(18)->withoutOverlapping();
 *     $schedule->command('nmi:billing rebillCross1')->hourlyAt(44)->withoutOverlapping();
 *     $schedule->command('nmi:billing rebillCross2')->hourlyAt(52)->withoutOverlapping();
 *
 * ...with a single fast dispatcher. Note the SHORT expiresAt — the opposite of
 * the legacy 24h default that silently skipped runs for a day after a crash.
 */

use Illuminate\Console\Scheduling\Schedule;

function exampleSchedule(Schedule $schedule): void
{
    $schedule->command('billing:dispatch')
        ->everyMinute()
        ->withoutOverlapping(2)          // lock auto-expires after 2 min
        ->runInBackground();

    // Optional: stagger heavy types onto their own ticks if desired.
    // $schedule->command('billing:dispatch --type=settle')->everyFiveMinutes();
}

/*
 * Queue workers (supervisor / horizon), e.g.:
 *   php artisan queue:work {connection} --queue=billing --tries=3 --backoff=60,300,900
 *
 * Start with QUEUE_CONNECTION=database (transactional with the schedule table,
 * no Redis-cluster slot gotchas). Move to Redis/Horizon later if needed.
 */
