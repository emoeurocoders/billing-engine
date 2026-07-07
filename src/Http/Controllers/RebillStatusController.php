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

        // Recovery watch: pending rebills wrongly parked into a FUTURE cycle
        // (never charged) — the timezone-bug victims still awaiting recovery. The
        // claim_run marker is essential: it means the row was DISPATCHED then
        // deferred, which separates a real victim from a legitimately future-due
        // row (a member genuinely billing next cycle, which has no claim_run).
        $nextCycleStart = $now->copy()->startOfMonth()->addMonth()->toDateString();
        $parkedNextCycle = (int) $sched()->where('status', 'pending')->where('attempts', 0)
            ->where('meta', 'like', '%claim_run%')
            ->where('next_action_at', '>=', $nextCycleStart)
            ->count();

        // Progress: processed vs (processed + still-pending-for-the-day).
        $dayTotal = $processed + $duePending;
        $progress = $dayTotal > 0 ? round($processed / $dayTotal * 100, 1) : 0.0;

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
                'pending'   => ['count' => $duePending, 'amount' => round($duePendingAmount, 2)],
                'backlog'   => ['count' => $backlog],
                'parked_next_cycle' => ['count' => $parkedNextCycle],
                'approval_rate' => $attTotal > 0 ? round($approved / $attTotal * 100, 1) : 0,
            ],
        ]);
    }
}
