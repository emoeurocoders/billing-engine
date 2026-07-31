<?php

namespace Omni\BillingEngine\Loggers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Omni\BillingEngine\Events\BillingDead;
use Omni\BillingEngine\Events\BillingSkipped;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\Clock;

/**
 * Persists the outcomes that never reach the attempt log: SKIP (a guard deferred
 * the row) and DEAD (a guard killed it). One row per occurrence in
 * billing_events_{vertical}.
 *
 * Until this existed, a skip left NO durable trace — BillingHandler::defer() just
 * returns the row to 'pending' with a later next_action_at and fires a log-only
 * event, so the only evidence was a line in the log file. Reporting had to infer
 * skips from claim timestamps, which is exact for today but decays as rows are
 * re-claimed (a no-MID skip retries the very next day), making history impossible.
 *
 * This is a pure EVENT SUBSCRIBER: it adds no call into the billing path and
 * touches no existing table. Writes are fully guarded — a broken/missing events
 * table must never stop a charge, exactly like BillingLogSubscriber.
 */
class BillingEventLogger
{
    public const SKIPPED = 'skipped';
    public const DEAD    = 'dead';

    public function onSkipped(BillingSkipped $e): void
    {
        $this->record($e->ctx, self::SKIPPED, $e->reason);
    }

    public function onDead(BillingDead $e): void
    {
        $this->record($e->ctx, self::DEAD, $e->reason);
    }

    /** @return array<class-string,string> */
    public function subscribe(): array
    {
        return [
            BillingSkipped::class => 'onSkipped',
            BillingDead::class    => 'onDead',
        ];
    }

    private function record(BillingContext $ctx, string $outcome, ?string $reason): void
    {
        try {
            // Billing-tz wall clock, matching next_action_at / claimed_at / the
            // attempt log's `date`. Never Carbon::now() — that is the app's UTC.
            $now = Clock::now();

            DB::connection(config('billing-engine.events.connection'))
                ->table(config('billing-engine.events.table', 'billing_events_sports'))
                ->insert([
                    'schedule_id'  => $ctx->row->id,
                    'member_id'    => $ctx->memberId(),
                    'billing_type' => $ctx->billingType,
                    'card_type'    => $ctx->cardType(),
                    // The MID actually resolved if we got that far, else the sticky
                    // one — for a no_usable_mid skip that IS the MID that failed.
                    'mid_id'       => $ctx->mid?->midId ?? $ctx->midId(),
                    'amount'       => $ctx->amount(),
                    'outcome'      => $outcome,
                    'reason'       => $reason !== null ? substr($reason, 0, 64) : null,
                    'cycle'        => $ctx->cycle(),
                    'occurred_at'  => $now,
                    'created_at'   => $now,
                ]);
        } catch (\Throwable $e) {
            // Never let reporting break a billing run (table not migrated yet,
            // connection down, ...). The log-file trail still records the action.
            //
            // But say so ONCE per process: swallowing this silently would mean
            // skips quietly stop being recorded and the dashboard just shows
            // fewer of them, with nothing anywhere to explain why.
            $this->warnOnce($e);
        }
    }

    private function warnOnce(\Throwable $e): void
    {
        static $warned = false;

        if ($warned) {
            return; // one line per run, not one per skipped member
        }

        $warned = true;

        try {
            $channel = config('billing-engine.logging.channel');
            ($channel ? Log::channel($channel) : Log::channel())->warning(
                'billing events: could not record outcome — skips/kills will be missing from reporting',
                ['error' => $e->getMessage(), 'table' => config('billing-engine.events.table')]
            );
        } catch (\Throwable $ignored) {
            // Logging about a logging failure must not throw either.
        }
    }
}
