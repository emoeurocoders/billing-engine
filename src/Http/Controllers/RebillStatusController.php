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

        // --- Forward-looking: what is still scheduled/pending ---
        // Due on $date and still awaiting (not yet charged this cycle).
        $dueRow = $sched()->where('status', 'pending')
            ->whereRaw('DATE(next_action_at) = ?', [$date])
            ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) amt')->first();
        $duePending       = (int) ($dueRow->c ?? 0);
        $duePendingAmount = (float) ($dueRow->amt ?? 0);

        // Live backlog: pending, never charged, already due — should be ~0.
        $backlog = (int) $sched()->where('status', 'pending')->where('attempts', 0)
            ->where('next_action_at', '<=', $now)->count();

        // Recovery watch: pending rebills wrongly parked into a FUTURE cycle
        // (never charged) — the timezone-bug victims still awaiting recovery.
        $parkedNextCycle = (int) $sched()->where('status', 'pending')->where('attempts', 0)
            ->where('next_action_at', '>=', $now->copy()->addDays(20)->toDateString())
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
