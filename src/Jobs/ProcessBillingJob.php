<?php

namespace Omni\BillingEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Omni\BillingEngine\Models\BillingSchedule;
use Omni\BillingEngine\Registry\BillingHandlerRegistry;
use Omni\BillingEngine\Support\BillingContext;

/**
 * Processes ONE claimed schedule row. Isolated: a failure here fails this row
 * only, never the batch — the exact fragility that killed the legacy loops.
 * Retries/backoff come from config; the per-MID lock prevents hammering a MID.
 */
class ProcessBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    /** @var int[] */
    public array $backoff;

    public function __construct(public int $scheduleId)
    {
        $this->tries   = (int) config('billing-engine.queue.tries', 3);
        $this->backoff = (array) config('billing-engine.queue.backoff', [60, 300, 900]);
        $this->onConnection(config('billing-engine.queue.connection'));
        $this->onQueue(config('billing-engine.queue.name', 'billing'));
    }

    public function middleware(): array
    {
        $row = BillingSchedule::find($this->scheduleId);
        $key = $row?->mid_id ?: 'no-mid';

        // Serialise charges per MID to respect processor pacing without a global sleep.
        return [(new WithoutOverlapping("billing-mid:{$key}"))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(BillingHandlerRegistry $registry): void
    {
        /** @var BillingSchedule|null $row */
        $row = BillingSchedule::find($this->scheduleId);

        // Only act on rows we actually claimed; anything else was already handled.
        if (!$row || $row->status !== BillingSchedule::STATUS_CLAIMED) {
            return;
        }

        $type   = $row->billing_type;
        $config = config("billing-engine.types.{$type}", []);

        $ctx = new BillingContext(
            row: $row,
            vertical: config('billing-engine.vertical'),
            billingType: $type,
            typeConfig: $config,
        );

        $registry->resolve($type)->handle($ctx);
    }

    public function failed(\Throwable $e): void
    {
        // Release the claim so the dispatcher can re-pick it next cycle.
        $row = BillingSchedule::find($this->scheduleId);
        if ($row && $row->status === BillingSchedule::STATUS_CLAIMED) {
            $row->status     = BillingSchedule::STATUS_PENDING;
            $row->claimed_at = null;
            $row->save();
        }
    }
}
