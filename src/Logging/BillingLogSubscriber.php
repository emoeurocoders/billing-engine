<?php

namespace Omni\BillingEngine\Logging;

use Illuminate\Support\Facades\Log;
use Omni\BillingEngine\Events\BillingAttempting;
use Omni\BillingEngine\Events\BillingDead;
use Omni\BillingEngine\Events\BillingDeclined;
use Omni\BillingEngine\Events\BillingSkipped;
use Omni\BillingEngine\Events\BillingSteppedDown;
use Omni\BillingEngine\Events\BillingSucceeded;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\MidDecision;

/**
 * Writes a log line for EVERY billing action to the configured channel — the
 * replacement for the legacy NMIBilling::log() per-member trail. Registered from
 * the service provider when `billing-engine.logging.enabled` is true.
 *
 * Coverage (this is the "every action" audit the DB attempt log can't give you,
 * because guard skips/deads never reach the charge/record step):
 *   ATTEMPTING (debug) · SUCCESS · DECLINE · SKIP · DEAD · STEPDOWN (info)
 *
 * Each entry has a human line AND a structured context array (so a JSON channel
 * gets queryable fields). Logging never throws into the billing flow — a broken
 * channel must not stop a charge — so calls are guarded.
 */
class BillingLogSubscriber
{
    public function onAttempting(BillingAttempting $e): void
    {
        $this->write('debug', $e->ctx, 'ATTEMPTING');
    }

    public function onSucceeded(BillingSucceeded $e): void
    {
        $mid = $this->midInfo($e->ctx, $e->mid);

        $this->write('info', $e->ctx, 'SUCCESS', "{$mid['label']} tr={$e->result->transactionId}", array_merge([
            'result'         => 'success',
            'transaction_id' => $e->result->transactionId,
        ], $mid['context']));
    }

    public function onDeclined(BillingDeclined $e): void
    {
        $mid = $this->midInfo($e->ctx, $e->mid);

        $this->write('info', $e->ctx, 'DECLINE', "{$mid['label']} code={$e->result->responseCode}", array_merge([
            'result'           => 'decline',
            'decline_code'     => $e->result->responseCode,
            'raw_decline_code' => $e->result->raw['response_code'] ?? null,
            'decline_reason'   => $e->result->responseText,
            'transaction_id'   => $e->result->transactionId,
        ], $mid['context']));
    }

    public function onSkipped(BillingSkipped $e): void
    {
        $this->write('info', $e->ctx, 'SKIP', "reason={$e->reason}", ['result' => 'skip', 'reason' => $e->reason]);
    }

    public function onDead(BillingDead $e): void
    {
        $this->write('info', $e->ctx, 'DEAD', "reason={$e->reason}", ['result' => 'dead', 'reason' => $e->reason]);
    }

    public function onSteppedDown(BillingSteppedDown $e): void
    {
        $p = $e->plan;
        $this->write('info', $e->ctx, 'STEPDOWN', "to={$p->amount} after={$p->delayDays}d mid={$p->midStrategy}", [
            'result'       => 'stepdown',
            'to_amount'    => $p->amount,
            'after_days'   => $p->delayDays,
            'mid_strategy' => $p->midStrategy,
        ]);
    }

    /** @return array<class-string,string> */
    public function subscribe(): array
    {
        return [
            BillingAttempting::class  => 'onAttempting',
            BillingSucceeded::class   => 'onSucceeded',
            BillingDeclined::class    => 'onDeclined',
            BillingSkipped::class     => 'onSkipped',
            BillingDead::class        => 'onDead',
            BillingSteppedDown::class => 'onSteppedDown',
        ];
    }

    /**
     * Build the MID clause + context, making REDIRECTS visible. The resolver
     * follows redirect_mid internally and returns the final MID; the ORIGINAL
     * sticky MID is still on the schedule row. When they differ:
     *   - normal sticky   → `redirect_from={original}` (a redirect fired)
     *   - a step-down MID  → `mid_via={strategy} from={original}` (match/new rung)
     *
     * @return array{label:string,context:array}
     */
    private function midInfo(BillingContext $ctx, MidDecision $mid): array
    {
        $resolved = $mid->midId;
        $original = $ctx->midId();
        $label    = "mid={$resolved}";
        $context  = ['mid' => $resolved];

        if ($original && $original !== $resolved) {
            $strategy = $ctx->row->meta['mid_strategy'] ?? null;
            $context['mid_original'] = $original;

            if ($strategy) {
                $label .= " mid_via={$strategy} from={$original}";
                $context['mid_via'] = $strategy;
            } else {
                $label .= " redirect_from={$original}";
                $context['redirect'] = true;
            }
        }

        return ['label' => $label, 'context' => $context];
    }

    private function write(string $level, BillingContext $ctx, string $result, string $detail = '', array $extra = []): void
    {
        try {
            $message = sprintf(
                '%s %s member=%s amount=%.2f %s%s',
                $ctx->billingType,
                $ctx->cardType(),
                $ctx->memberId(),
                $ctx->amount(),
                $result,
                $detail !== '' ? " {$detail}" : ''
            );

            $context = array_merge([
                'member_id'   => $ctx->memberId(),
                'type'        => $ctx->billingType,
                'card'        => $ctx->cardType(),
                'amount'      => $ctx->amount(),
                'cycle'       => $ctx->cycle(),
                'schedule_id' => $ctx->row->id,
                'vertical'    => $ctx->vertical,
            ], $extra);

            $this->channel()->{$level}($message, $context);
        } catch (\Throwable $e) {
            // Never let logging break a billing run.
        }
    }

    private function channel()
    {
        $channel = config('billing-engine.logging.channel');

        return $channel ? Log::channel($channel) : Log::channel();
    }
}
