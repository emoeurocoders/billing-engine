<?php

namespace Omni\BillingEngine\Handlers;

use Carbon\Carbon;
use Omni\BillingEngine\Models\BillingSchedule;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GatewayResult;

/**
 * Settles an auth-only transaction by capturing it N days later (settleAuths).
 * Different lifecycle from a sale: it captures an existing authorisation
 * (doCapture on the original transaction ref) rather than charging anew.
 */
class SettleHandler extends BillingHandler
{
    protected function charge(BillingContext $ctx, array $payload): GatewayResult
    {
        $ref = (string) ($ctx->row->source_tr_id ?? '');
        return $this->gateway->capture($ref);
    }

    protected function scheduleNext(BillingContext $ctx, GatewayResult $r): void
    {
        // A settle is one-and-done: capture succeeds → done, else retry tomorrow.
        $ctx->row->attempts = ($ctx->row->attempts ?? 0) + 1;

        if ($r->approved) {
            $ctx->row->status = BillingSchedule::STATUS_DONE;
        } else {
            $ctx->row->status            = BillingSchedule::STATUS_PENDING;
            $ctx->row->last_decline_code = $r->responseCode;
            $ctx->row->next_action_at    = Carbon::now()->addDay();
        }

        $ctx->row->save();
    }
}
