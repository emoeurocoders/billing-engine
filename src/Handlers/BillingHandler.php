<?php

namespace Omni\BillingEngine\Handlers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Omni\BillingEngine\Contracts\AttemptLogger;
use Omni\BillingEngine\Contracts\CardVault;
use Omni\BillingEngine\Contracts\GatewayClient;
use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Events\BillingAttempting;
use Omni\BillingEngine\Events\BillingDead;
use Omni\BillingEngine\Events\BillingDeclined;
use Omni\BillingEngine\Events\BillingSkipped;
use Omni\BillingEngine\Events\BillingSteppedDown;
use Omni\BillingEngine\Events\BillingSucceeded;
use Omni\BillingEngine\Models\BillingSchedule;
use Omni\BillingEngine\Pipeline\GuardRunner;
use Omni\BillingEngine\StepDown\StepDownPlanner;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\CardData;
use Omni\BillingEngine\Support\GatewayResult;
use Omni\BillingEngine\Support\GuardResult;
use Omni\BillingEngine\Support\MidDecision;
use Omni\BillingEngine\Support\StepDownPlan;

/**
 * Template method for one billing attempt. handle() is FINAL — the flow is
 * fixed; verticals customise behaviour by overriding the protected hooks
 * (resolveMid, buildPayload, charge, onApproved, onDeclined, scheduleNext).
 *
 * This single class replaces the ~1,000 lines duplicated across
 * rebillCC/PP/Settles/Cross1/Cross2.
 */
abstract class BillingHandler
{
    public function __construct(
        protected MidResolver $mids,
        protected GatewayClient $gateway,
        protected AttemptLogger $log,
        protected GuardRunner $guards,
        protected StepDownPlanner $stepDown,
        protected CardVault $vault,
    ) {}

    final public function handle(BillingContext $ctx): void
    {
        Event::dispatch(new BillingAttempting($ctx));

        // 1. Guards (config-ordered, vertical-extensible)
        $verdict = $this->guards->run($ctx, $ctx->typeConfig['guards'] ?? []);
        if (!$verdict->passed()) {
            $this->finishGuarded($ctx, $verdict);
            return;
        }

        // 2. MID selection (sticky vs rotation — overridable)
        $mid = $this->resolveMid($ctx);
        if (!$mid) {
            $this->defer($ctx, 'no_active_mid');
            return;
        }
        $ctx->mid = $mid;

        // 3. Charge (overridable: rebill / charge / capture)
        $result = $this->charge($ctx, $this->buildPayload($ctx, $mid));
        $ctx->result = $result;

        // 4. Record everywhere (MID state, attempt log, schedule row)
        $this->mids->recordResult($mid, $result->approved, $this->resultDetails($result));
        $this->log->record($ctx, $result->approved, $result->responseCode, $result->transactionId);

        // 5. Outcome hooks + next due date
        $result->approved ? $this->onApproved($ctx, $mid, $result)
                          : $this->onDeclined($ctx, $mid, $result);

        $this->scheduleNext($ctx, $result);
    }

    /* ----- Overridable hooks (sensible defaults) ----------------------- */

    /**
     * Sticky by default; rotation handlers override or set selection=rotation.
     * A step-down may pin a different MID strategy for this attempt (set on the
     * row's meta when the previous rung was scheduled).
     */
    protected function resolveMid(BillingContext $ctx): ?MidDecision
    {
        $strategy = $ctx->row->meta['mid_strategy'] ?? null;

        if ($strategy === 'match') {
            // Retry on a different MID sharing the original's descriptor.
            return $this->mids->resolveMatchingMid((string) $ctx->midId(), $ctx);
        }

        if ($strategy === 'new') {
            return $this->mids->resolveRotationMid($ctx);
        }

        return $ctx->selection() === 'rotation'
            ? $this->mids->resolveRotationMid($ctx)
            : $this->mids->resolveStickyMid((string) $ctx->midId(), $ctx);
    }

    /** Canonical payload; gateway adapter translates to NMI/Inovio keys. */
    protected function buildPayload(BillingContext $ctx, MidDecision $mid): array
    {
        $meta = $ctx->row->meta ?? [];

        return [
            'amount'       => $ctx->amount(),
            'mid_id'       => $mid->midId,
            'member_id'    => $ctx->memberId(),
            'source_tr_id' => $ctx->row->source_tr_id,
            'descriptor'   => $mid->descriptor,
            'udf_1'        => $meta['udf_1'] ?? null,
            'udf_2'        => $ctx->typeConfig['udf2'] ?? null,
            // Risk/routing fields the legacy doRebill also sent (from seeded meta).
            'ip'           => $meta['ip'] ?? null,
            'country'      => $meta['country'] ?? ($meta['tui_bill_country'] ?? null),
            'device'       => $meta['device'] ?? ($meta['affiliate'] ?? null),
        ];
    }

    /**
     * Default charge path. Mirrors the legacy `if($az){ doCharge }else{ doRebill }`:
     * if a stored ("AZ") card resolves for this member, charge it via the gateway's
     * card path; otherwise rebill the token. SettleHandler overrides with capture().
     */
    protected function charge(BillingContext $ctx, array $payload): GatewayResult
    {
        if ($card = $this->resolveCard($ctx)) {
            return $this->gateway->charge($this->withCard($ctx, $payload, $card));
        }

        return $this->gateway->rebill($payload);
    }

    /**
     * Resolve a stored card for this member, or null to use the token rebill path.
     * Honours the `az.enabled` master switch so a vertical can turn the whole
     * stored-card flow off without unbinding its vault.
     */
    protected function resolveCard(BillingContext $ctx): ?CardData
    {
        if (!config('billing-engine.az.enabled', true)) {
            return null;
        }

        return $this->vault->resolve($ctx);
    }

    /** Fold stored-card data into the canonical charge payload (adds order + billing). */
    protected function withCard(BillingContext $ctx, array $payload, CardData $card): array
    {
        $az = (array) config('billing-engine.az', []);

        $payload['card_number'] = $card->cardNumber;
        $payload['cvv']         = $card->cvv;
        $payload['card_exp']    = $card->cardExp ?? ($ctx->row->meta['card_exp'] ?? null);
        $payload['udf_3']       = $card->meta['udf_3'] ?? ($az['udf_3'] ?? 'AZ');
        $payload['billing']     = $card->billing ?: ($ctx->row->meta['billing'] ?? []);
        $payload['order']       = [
            'order_id'   => $card->meta['order_id'] ?? null, // adapter defaults if null
            'order_desc' => $card->meta['order_desc'] ?? ($az['order_desc'] ?: strtoupper($ctx->vertical)),
        ];

        return $payload;
    }

    protected function onApproved(BillingContext $ctx, MidDecision $mid, GatewayResult $r): void
    {
        Event::dispatch(new BillingSucceeded($ctx, $mid, $r));
    }

    protected function onDeclined(BillingContext $ctx, MidDecision $mid, GatewayResult $r): void
    {
        Event::dispatch(new BillingDeclined($ctx, $mid, $r));
    }

    /**
     * Approval → next cycle (ladder reset). Decline → step down if a rung
     * applies, else defer to next cycle (or die if the ladder is exhausted and
     * the type is configured `on_exhausted => 'dead'`).
     */
    protected function scheduleNext(BillingContext $ctx, GatewayResult $r): void
    {
        $row  = $ctx->row;
        $days = (int) ($ctx->typeConfig['cycle_days'] ?? config('billing-engine.cycle_days', 30));
        $row->attempts = ($row->attempts ?? 0) + 1;

        if ($r->approved) {
            $row->status         = BillingSchedule::STATUS_DONE;
            $row->next_action_at = Carbon::now()->addDays($days);
            $row->step           = 0;
            $row->meta           = array_diff_key($row->meta ?? [], ['mid_strategy' => null]);
            $row->save();
            return;
        }

        $row->last_decline_code = $r->responseCode;

        // Step down to the next rung if the decline code matches a rule.
        if ($plan = $this->stepDown->plan($ctx, $r)) {
            $this->applyStepDown($ctx, $plan);
            return;
        }

        // No (further) step-down available.
        $onExhausted = $ctx->typeConfig['stepdowns']['on_exhausted'] ?? 'defer';
        if (($row->step ?? 0) > 0 && $onExhausted === 'dead') {
            $row->status = BillingSchedule::STATUS_DEAD;
        } else {
            $row->status         = BillingSchedule::STATUS_PENDING;
            $row->next_action_at = Carbon::now()->addDays($days); // wait next cycle
        }

        $row->save();
    }

    /** Schedule the next step-down rung: lower amount, later date, maybe new MID. */
    protected function applyStepDown(BillingContext $ctx, StepDownPlan $plan): void
    {
        $row = $ctx->row;

        if ($plan->amount !== null) {
            $row->amount = $plan->amount;
        }

        $row->step           = ($row->step ?? 0) + 1;
        $row->meta           = array_merge($row->meta ?? [], ['mid_strategy' => $plan->midStrategy]);
        $row->status         = BillingSchedule::STATUS_PENDING;
        $row->next_action_at = Carbon::now()->addDays($plan->delayDays);
        $row->save();

        Event::dispatch(new BillingSteppedDown($ctx, $plan));
    }

    /* ----- Internal terminal paths ------------------------------------- */

    protected function finishGuarded(BillingContext $ctx, GuardResult $v): void
    {
        if ($v->isDead()) {
            $ctx->row->status = BillingSchedule::STATUS_DEAD;
            $ctx->row->save();
            Event::dispatch(new BillingDead($ctx, $v->reason));
            return;
        }

        $this->defer($ctx, $v->reason ?? 'skipped');
    }

    protected function defer(BillingContext $ctx, string $reason): void
    {
        $days = (int) ($ctx->typeConfig['cycle_days'] ?? config('billing-engine.cycle_days', 30));
        $ctx->row->status         = BillingSchedule::STATUS_PENDING;
        $ctx->row->next_action_at = Carbon::now()->addDays($days);
        $ctx->row->save();
        Event::dispatch(new BillingSkipped($ctx, $reason));
    }

    protected function resultDetails(GatewayResult $r): array
    {
        return [
            'declineCode'      => $r->responseCode,
            'declineReason'    => $r->responseText,
            'transactionId'    => $r->transactionId,
            'bankResponseCode' => $r->raw['processor_response_code'] ?? null,
        ];
    }
}
