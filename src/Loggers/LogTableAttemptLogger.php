<?php

namespace Omni\BillingEngine\Loggers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\AttemptLogger;
use Omni\BillingEngine\Support\BillingContext;

/**
 * Legacy attempt logger — writes the existing per-type/per-vertical log tables
 * (rebill_sports, rebill_games_sports, rebill_vod_sports, conversion_sports,
 * settle_sports, and cross-product combos like rebill_sports_fit). Keeps
 * existing reporting + the still-running step-down/auth-retry crons working
 * during migration.
 *
 * All these tables share ONE schema, so a single insert shape works. The only
 * thing that varies is the table NAME — and for crosses it can depend on the
 * member's cross product at runtime. So the name is resolved as:
 *   1. an app-bound closure `billing.logTable($ctx): string`  (most flexible)
 *   2. else the type's static `log_table` config
 *   3. else 'rebill_sports'
 */
class LogTableAttemptLogger implements AttemptLogger
{
    private function tableName(BillingContext $ctx): string
    {
        if (app()->bound('billing.logTable')) {
            return (string) app('billing.logTable')($ctx);
        }

        return $ctx->logTable() ?? 'rebill_sports';
    }

    private function table(BillingContext $ctx)
    {
        return DB::connection(config('billing-engine.log.connection', 'omnistats'))
            ->table($this->tableName($ctx));
    }

    private function cycleWindow(): array
    {
        $days = (int) config('billing-engine.cycle_days', 30);
        return [Carbon::now()->subDays($days - 1)->toDateString(), Carbon::now()->toDateString()];
    }

    /**
     * Attempts for this member inside the cycle window, EXCLUDING the anchor
     * charge this rebill was seeded from (source_tr_id). Without that exclusion
     * every on-schedule member is falsely flagged "already attempted this cycle":
     * their previous cycle's charge — the very transaction this rebill derives
     * from — is always ~cycle_days-1 back, i.e. inside a rolling window. Only a
     * DIFFERENT tr_id (the engine's own retry, or a dual-run legacy charge) is a
     * genuine same-cycle attempt.
     */
    private function windowedAttempts(BillingContext $ctx)
    {
        [$from, $to] = $this->cycleWindow();

        return $this->table($ctx)
            ->where('uid', $ctx->memberId())
            ->whereBetween(DB::raw('DATE(`date`)'), [$from, $to])
            ->when($ctx->row->source_tr_id, fn ($q, $trId) => $q->where('tr_id', '!=', $trId));
    }

    public function alreadyAttempted(BillingContext $ctx): bool
    {
        return $this->windowedAttempts($ctx)->exists();
    }

    public function lastApprovedThisCycle(BillingContext $ctx): bool
    {
        $last = $this->windowedAttempts($ctx)->orderByDesc('date')->first();

        return $last && (int) $last->result === 1;
    }

    public function record(BillingContext $ctx, bool $approved, ?string $declineCode, ?string $transactionId): void
    {
        $this->table($ctx)->insert([
            'uid'          => $ctx->memberId(),
            'card_type'    => $ctx->cardType(),
            'date'         => Carbon::now(),
            'result'       => $approved ? 1 : 0,
            'decline_code' => $declineCode,
            'amount'       => $ctx->amount(),
            'retry'        => $approved ? 'completed' : 'pending',
            'tr_id'        => $transactionId,
            'processor'    => config('billing-engine.gateway.driver', 'nmi'),
        ]);
    }
}
