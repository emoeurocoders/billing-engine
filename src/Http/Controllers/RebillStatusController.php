<?php

namespace Omni\BillingEngine\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Support\Clock;

/**
 * Read-only day-by-day rebill status. "Processed" is measured from ACTIVITY
 * (the attempt log + rows killed that day), NOT from next_action_at — because a
 * successful rebill advances next_action_at to next cycle, so the schedule row
 * no longer points at the day it was billed. "Pending" is the live backlog.
 */
class RebillStatusController extends Controller
{
    public function index()
    {
        return view('billing-engine::rebill-status', [
            'vertical' => config('billing-engine.vertical', 'sports'),
            'prefix'   => trim((string) config('billing-engine.dashboard.prefix', 'billing'), '/'),
        ]);
    }

    public function apiData(Request $request): JsonResponse
    {
        $tz      = Clock::tz();
        $now     = Clock::now();
        $today   = Clock::today();
        $date    = (string) $request->query('date', $today);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $today;
        }
        $isToday = $date === $today;

        $schedConn  = config('billing-engine.schedule.connection');
        $schedTable = config('billing-engine.schedule.table');
        $attConn    = config('billing-engine.attempts.connection');
        $attTable   = config('billing-engine.attempts.table');

        $sched = fn () => DB::connection($schedConn)->table($schedTable)->where('billing_type', 'rebill');
        $att   = fn () => DB::connection($attConn)->table($attTable)->where('billing_type', 'rebill');

        // --- Activity ON $date: the charges the engine actually made ---
        $attempts = $att()
            ->whereRaw('DATE(`date`) = ?', [$date])
            ->selectRaw('result, COUNT(*) c, COALESCE(SUM(amount),0) amt')
            ->groupBy('result')->get()->keyBy('result');
        $approved     = (int) ($attempts[1]->c ?? 0);
        $approvedAmt  = (float) ($attempts[1]->amt ?? 0);
        $declined     = (int) ($attempts[0]->c ?? 0);
        $attTotal     = $approved + $declined;

        // Rows KILLED on $date (guard dead: refund/cancel/chargeback/max-declines).
        $killed = (int) $sched()->where('status', 'dead')
            ->whereRaw('DATE(updated_at) = ?', [$date])->count();

        $processed = $approved + $declined + $killed;

        // --- Pending, made TIME-AWARE (rebills are spread across the day by each
        // member's rebill time-of-day, so "scheduled today" is mostly not due yet). ---

        // DUE NOW: pending rebills whose time has ARRIVED but that aren't charged
        // yet — the real backlog to watch. Live for today; leftover-as-of-that-day
        // for a historical view.
        $dueNowQ = $sched()->where('status', 'pending');
        $isToday
            ? $dueNowQ->where('next_action_at', '<=', $now)
            : $dueNowQ->whereRaw('DATE(next_action_at) <= ?', [$date]);
        $dueNowRow = $dueNowQ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) amt')->first();
        $dueNow    = (int) ($dueNowRow->c ?? 0);
        $dueNowAmt = (float) ($dueNowRow->amt ?? 0);

        // SCHEDULED for the day: the whole day's remaining rebill queue (most simply
        // not due yet — informational volume, not a backlog).
        $schedRow = $sched()->where('status', 'pending')
            ->whereRaw('DATE(next_action_at) = ?', [$date])
            ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) amt')->first();
        $scheduled       = (int) ($schedRow->c ?? 0);
        $scheduledAmount = (float) ($schedRow->amt ?? 0);

        // --- Hourly funnel (MST wall-clock; next_action_at is stored in the billing
        // tz). Each row is meant to read as an identity:
        //
        //     Queued = Neg DB + Skipped + Attempted        (for hours already worked)
        //     Attempted = Declined + Billed
        //
        // QUEUED is deliberately built from TWO sources, because a row leaves its
        // own hour the moment it is charged (a success pushes next_action_at to
        // next cycle). So "queued at hour H" =
        //     rows DISPATCHED in H (claimed_at)  +  rows still PENDING due in H.
        // Upcoming hours have no claims yet, so that reduces to pure schedule;
        // worked hours have (almost) nothing left pending, so it reduces to what
        // the dispatcher actually picked up. A row still sitting pending hours
        // after it was due stays visible in its own hour — that is the backlog.
        $schedByHour = $sched()->where('status', 'pending')
            ->whereRaw('DATE(next_action_at) = ?', [$date])
            ->selectRaw('HOUR(next_action_at) h, COUNT(*) c, COALESCE(SUM(amount),0) amt')
            ->groupByRaw('HOUR(next_action_at)')->get()->keyBy('h');

        // Every row the claimer picked up on $date, whatever became of it (billed /
        // declined / skipped / killed / still in flight). Read ONCE and reduced in
        // PHP, because it feeds both the dispatched counts and the skip derivation
        // below — and claimed_at carries no index, so each pass over it is a scan
        // of a multi-million-row table. A range predicate (not DATE(claimed_at))
        // keeps it sargable, so adding INDEX(claimed_at) later fixes the cost with
        // no query change. Volume here is one day of claims (~10-20k rows).
        $claimed = $sched()
            ->whereBetween('claimed_at', [$date . ' 00:00:00', $date . ' 23:59:59'])
            ->selectRaw('id, status, amount, HOUR(claimed_at) h')
            ->get();

        $dispByHour = $dispAmtByHour = [];
        foreach ($claimed as $row) {
            $h = (int) $row->h;
            $dispByHour[$h]    = ($dispByHour[$h] ?? 0) + 1;
            $dispAmtByHour[$h] = ($dispAmtByHour[$h] ?? 0) + (float) $row->amount;
        }

        $procByHour = $att()->whereRaw('DATE(`date`) = ?', [$date])
            ->selectRaw('HOUR(`date`) h, COUNT(*) c, SUM(result = 1) ok, COALESCE(SUM(CASE WHEN result = 1 THEN amount ELSE 0 END),0) amt')
            ->groupByRaw('HOUR(`date`)')->get()->keyBy('h');

        // Killed that hour. NOTE: this is every DEAD row, not only negative-db hits
        // — max_declines and already_attempted_approved also kill a row, and the
        // guard's reason string is not persisted, so the three cannot be split
        // apart until a skip/dead reason is recorded. Column reads "Neg DB"
        // because neg-db is the overwhelming majority.
        $deadByHour = $sched()->where('status', 'dead')
            ->whereRaw('DATE(updated_at) = ?', [$date])
            ->selectRaw('HOUR(updated_at) h, COUNT(*) c')
            ->groupByRaw('HOUR(updated_at)')->get()->keyBy('h');

        $skippedByHour = $this->skippedByHour($claimed, $att, $date, $isToday);

        $curHour = (int) $now->format('G');
        $hourly  = [];
        for ($h = 0; $h < 24; $h++) {
            $s = $schedByHour[$h] ?? null;
            $p = $procByHour[$h] ?? null;
            $k = $deadByHour[$h] ?? null;

            $queued    = (int) ($s->c ?? 0) + ($dispByHour[$h] ?? 0);
            $queuedAmt = (float) ($s->amt ?? 0) + ($dispAmtByHour[$h] ?? 0);
            $attempted = (int) ($p->c ?? 0);
            $billed    = (int) ($p->ok ?? 0);
            $negDb     = (int) ($k->c ?? 0);
            // null (not 0) on a historical day = "we can't know", rendered as a dash.
            $skipped   = $skippedByHour === null ? null : ($skippedByHour[$h] ?? 0);

            if ($queued === 0 && $attempted === 0 && $negDb === 0 && !$skipped) {
                continue; // skip empty hours
            }

            $hourly[] = [
                'hour'       => sprintf('%02d:00', $h),
                'queued'     => $queued,
                'queued_amt' => round($queuedAmt, 2),
                'neg_db'     => $negDb,
                'skipped'    => $skipped,
                'attempted'  => $attempted,
                'declined'   => $attempted - $billed,
                'billed'     => $billed,
                'billed_amt' => round((float) ($p->amt ?? 0), 2),
                'approval'   => $attempted > 0 ? round($billed / $attempted * 100, 1) : null,
                'state'      => !$isToday ? 'past' : ($h < $curHour ? 'past' : ($h === $curHour ? 'now' : 'future')),
            ];
        }

        // Recovery watch: pending rebills wrongly parked into a FUTURE cycle
        // (never charged) — the timezone-bug victims still awaiting recovery. The
        // claim_run marker is essential: it means the row was DISPATCHED then
        // deferred, which separates a real victim from a legitimately future-due
        // row (a member genuinely billing next cycle, which has no claim_run).
        // --- COMMENTED OUT (recovery complete); kept for easy restore. ---
        // $nextCycleStart = $now->copy()->startOfMonth()->addMonth()->toDateString();
        // $parkedNextCycle = (int) $sched()->where('status', 'pending')->where('attempts', 0)
        //     ->where('meta', 'like', '%claim_run%')
        //     ->where('next_action_at', '>=', $nextCycleStart)
        //     ->count();

        // MID outlook for the day's remaining queue: for every pending rebill
        // scheduled today, will its sticky MID actually take the charge?
        //
        // This has to REPRODUCE the resolver's decision rather than approximate it
        // with a WHERE clause, because usability depends on a SECOND row: both
        // DirectMidResolver::resolveStickyMid and the app's SportsMidBalancerAdapter
        // charge on  (redirect_mid ?: mid_id)  and then require THAT mid to be
        // active AND not killed/high_declines. So the registry is read whole (a few
        // hundred rows) and each schedule row is bucketed in PHP:
        //
        //   ok            → resolves on its own MID, will bill
        //   redirected    → sticky MID hands off to a redirect that IS live — these
        //                   bill today ONLY because the redirect exists
        //   dead_redirect → a redirect is set but its target is dead too → SKIPPED
        //   no_redirect   → no redirect to follow at all → SKIPPED
        //
        // The last two are both skips; they are kept apart because the fix differs
        // (add a redirect vs. repoint an existing one at a live MID).
        //
        // Where the two resolvers disagree — a redirect_mid pointing at a mid_id
        // that does not exist at all — this follows the ADAPTER (skip), since that
        // is what sports runs in production; DirectMidResolver would instead fall
        // back to the original row.
        $midsConn  = config('billing-engine.mids.connection');
        $midsTable = config('billing-engine.mids.table', 'mids');
        $midRows = DB::connection($midsConn)->table($midsTable)
            ->select('mid_id', 'redirect_mid', 'active', 'status')->get();

        $usable = $redirect = [];
        foreach ($midRows as $m) {
            $id = (string) $m->mid_id;
            $usable[$id] = (int) ($m->active ?? 0) === 1
                && !in_array((string) ($m->status ?? ''), ['killed', 'high_declines'], true);
            if (!empty($m->redirect_mid)) {
                $redirect[$id] = (string) $m->redirect_mid;
            }
        }

        $buckets = [];
        foreach (['ok', 'redirected', 'dead_redirect', 'no_redirect'] as $b) {
            $buckets[$b] = ['count' => 0, 'amount' => 0.0, 'mids' => 0];
        }

        $midQueue = $sched()->where('status', 'pending')
            ->whereRaw('DATE(next_action_at) = ?', [$date])
            ->selectRaw('mid_id, COUNT(*) c, COALESCE(SUM(amount),0) amt')
            ->groupBy('mid_id')->get();

        foreach ($midQueue as $row) {
            $midId       = (string) ($row->mid_id ?? '');
            $hasRedirect = isset($redirect[$midId]);
            // An unknown mid_id (or none at all) resolves to nothing — same skip as
            // a dead one, which is what the resolver does with it.
            $live = $usable[$redirect[$midId] ?? $midId] ?? false;

            $bucket = $live
                ? ($hasRedirect ? 'redirected' : 'ok')
                : ($hasRedirect ? 'dead_redirect' : 'no_redirect');

            $buckets[$bucket]['count']  += (int) $row->c;
            $buckets[$bucket]['amount'] += (float) $row->amt;
            $buckets[$bucket]['mids']++;
        }

        foreach ($buckets as $k => $b) {
            $buckets[$k]['amount'] = round($b['amount'], 2);
        }

        // Progress = processed vs the day's TOTAL scheduled volume (processed +
        // still-scheduled-today). Climbs toward 100% as the day's queue drains.
        $dayVolume = $processed + $scheduled;
        $progress  = $dayVolume > 0 ? round($processed / $dayVolume * 100, 1) : 100.0;

        return response()->json([
            'vertical'    => config('billing-engine.vertical', 'sports'),
            'date'        => $date,
            'is_today'    => $isToday,
            'tz'          => $tz,
            'updated_at'  => $now->toDateTimeString(),
            'progress'    => $progress,
            'cards' => [
                'billed'    => ['count' => $approved, 'amount' => round($approvedAmt, 2)],
                'declined'  => ['count' => $declined],
                'killed'    => ['count' => $killed],
                'processed' => ['count' => $processed],
                'due_now'   => ['count' => $dueNow, 'amount' => round($dueNowAmt, 2)],
                'scheduled' => ['count' => $scheduled, 'amount' => round($scheduledAmount, 2)],
                // 'parked_next_cycle' => ['count' => $parkedNextCycle], // COMMENTED OUT
                // Sticky MID dead, nothing to fall back to → skipped today.
                'inactive_mid' => $buckets['no_redirect'],
                // Sticky MID dead but a redirect carries them → these DO bill today.
                'redirect_mid' => $buckets['redirected'],
                // Redirect set but it points at another dead MID → also skipped.
                'dead_redirect' => $buckets['dead_redirect'],
                'approval_rate' => $attTotal > 0 ? round($approved / $attTotal * 100, 1) : 0,
            ],
            'hourly' => $hourly,
            // False ⇒ the Skipped column is blank because it can't be reconstructed
            // for a past day (see skippedByHour), not because nothing was skipped.
            'skipped_available' => $isToday,
        ]);
    }

    /**
     * Rows the engine picked up and then DEFERRED (guard skip / no usable MID),
     * bucketed by the hour they were dispatched.
     *
     * Skips are not persisted: BillingHandler::defer() just returns the row to
     * 'pending' with a later next_action_at and fires a log-only event. So a skip
     * is inferred as the residue of a claim that produced no charge:
     *
     *     claimed that day  AND  back to 'pending'  AND  no attempt-log row
     *
     * Each exclusion is exact, which is why this is a real number and not an
     * estimate: approvals leave the row 'done' and guard kills leave it 'dead';
     * a row still being worked is 'claimed'; a declined row IS pending but writes
     * an attempt row, so the anti-join drops it; and a job that crashed or was
     * reaped as an orphan has claimed_at nulled (ProcessBillingJob::failed and
     * DispatchCommand::reclaimStale), so it never reaches this set. What's left
     * is exactly the deferrals. The anti-join runs on schedule_id in PHP rather
     * than SQL because the attempt log may sit on another connection.
     *
     * TODAY ONLY, by design. claimed_at holds only the LATEST claim, so once a
     * skipped row is retried (a no-MID skip retries the very next day) its old
     * claim timestamp is overwritten and the skip vanishes from history. Any
     * historical count would silently shrink each day it is viewed, so we return
     * null and render a dash instead of a number we know decays. Fixing that
     * needs a persisted skip record — deliberately out of scope here.
     *
     * @param  \Illuminate\Support\Collection $claimed rows claimed on $date
     * @return array<int,int>|null hour => count, or null when not derivable
     */
    private function skippedByHour($claimed, callable $att, string $date, bool $isToday): ?array
    {
        if (!$isToday) {
            return null;
        }

        $candidates = $claimed->where('status', 'pending');

        if ($candidates->isEmpty()) {
            return [];
        }

        $charged = array_flip(array_map('intval', $att()
            ->whereRaw('DATE(`date`) = ?', [$date])
            ->whereNotNull('schedule_id')
            ->pluck('schedule_id')->all()));

        $byHour = [];
        foreach ($candidates as $row) {
            if (!isset($charged[(int) $row->id])) {
                $h = (int) $row->h;
                $byHour[$h] = ($byHour[$h] ?? 0) + 1;
            }
        }

        return $byHour;
    }
}
