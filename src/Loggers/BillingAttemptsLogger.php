<?php

namespace Omni\BillingEngine\Loggers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\AttemptLogger;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\Clock;

/**
 * The engine-owned, unified attempt log (billing_attempts_{vertical}). Holds
 * every billing_type in one table. This is the strategic destination; once it
 * has enough history, point reads here and drop the legacy tables.
 */
class BillingAttemptsLogger implements AttemptLogger
{
    private function table()
    {
        return DB::connection(config('billing-engine.attempts.connection'))
            ->table(config('billing-engine.attempts.table', 'billing_attempts_sports'));
    }

    private function cycleWindow(): array
    {
        $days = (int) config('billing-engine.cycle_days', 30);
        return [Clock::now()->subDays($days - 1)->startOfDay(), Clock::now()];
    }

    public function alreadyAttempted(BillingContext $ctx): bool
    {
        [$from, $to] = $this->cycleWindow();

        return $this->table()
            ->where('member_id', $ctx->memberId())
            ->where('billing_type', $ctx->billingType)
            ->whereBetween('date', [$from, $to])
            ->exists();
    }

    public function lastApprovedThisCycle(BillingContext $ctx): bool
    {
        [$from, $to] = $this->cycleWindow();

        $last = $this->table()
            ->where('member_id', $ctx->memberId())
            ->where('billing_type', $ctx->billingType)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->first();

        return $last && (int) $last->result === 1;
    }

    public function record(BillingContext $ctx, bool $approved, ?string $declineCode, ?string $transactionId): void
    {
        $now = Clock::now();

        $this->table()->insert([
            'member_id'      => $ctx->memberId(),
            'billing_type'   => $ctx->billingType,
            'cross_target'   => $ctx->row->meta['cross_target'] ?? null,
            'card_type'      => $ctx->cardType(),
            'mid_id'         => $ctx->mid?->midId ?? $ctx->midId(),
            'amount'         => $ctx->amount(),
            'result'         => $approved ? 1 : 0,
            'decline_code'   => $declineCode,
            'transaction_id' => $transactionId,
            'processor'      => config('billing-engine.gateway.driver', 'nmi'),
            'attempt_no'     => (int) ($ctx->row->attempts ?? 0) + 1,
            'retry'          => $approved ? 'completed' : 'pending',
            'schedule_id'    => $ctx->row->id,
            'cycle'          => $ctx->cycle(),
            'date'           => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }
}
