<?php

namespace Omni\BillingEngine\StepDown;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GatewayResult;
use Omni\BillingEngine\Support\StepDownPlan;

/**
 * Decides whether a declined attempt should step down, and to what. This is the
 * config-driven replacement for the legacy rebillStepDown* / stepDown* crons —
 * the rules live in each type's `stepdowns` config, keyed by decline code.
 *
 * Config shape (per billing_type):
 *   'stepdowns' => [
 *       'max_steps'        => 2,
 *       'on_exhausted'     => 'defer',          // defer | dead
 *       'country_excluded' => ['JM','PG','RE','BS'],
 *       'rules' => [
 *           // matched top-down; first match wins; gated by the row's current step
 *           ['step'=>0,'card_type'=>'cc','codes'=>['104','105','109','157'],'after_days'=>8,'to'=>19.55,'mid'=>'sticky'],
 *           ['step'=>0,'card_type'=>'cc','codes'=>['154','407'],'after_days'=>3,'to'=>null,'mid'=>'sticky'],
 *           ['step'=>1,'card_type'=>'cc','codes'=>['105','109','157'],'after_days'=>3,'to'=>19.55,'mid'=>'match'],
 *       ],
 *   ]
 *
 * Rule fields: `step` (current rung this rule applies at; omit = any), `card_type`
 * ('cc'|'pp'|'any'), `codes` (canonical decline codes), `after_days`, `to`
 * (null = keep current amount), `mid` ('sticky'|'match'|'new').
 */
class StepDownPlanner
{
    public function plan(BillingContext $ctx, GatewayResult $r): ?StepDownPlan
    {
        if ($r->approved) {
            return null;
        }

        $cfg = $ctx->typeConfig['stepdowns'] ?? null;
        if (empty($cfg) || empty($cfg['rules'])) {
            return null;
        }

        $step = (int) ($ctx->row->step ?? 0);
        if ($step >= (int) ($cfg['max_steps'] ?? 1)) {
            return null; // ladder exhausted
        }

        // Geo never-step-down list.
        $country = $ctx->row->meta['country'] ?? null;
        if ($country && in_array($country, (array) ($cfg['country_excluded'] ?? []), true)) {
            return null;
        }

        $code = (string) ($r->responseCode ?? '');
        $card = $ctx->cardType();

        foreach ($cfg['rules'] as $rule) {
            if (array_key_exists('step', $rule) && (int) $rule['step'] !== $step) {
                continue;
            }
            $ruleCard = $rule['card_type'] ?? 'any';
            if ($ruleCard !== 'any' && $ruleCard !== $card) {
                continue;
            }
            $codes = array_map('strval', (array) ($rule['codes'] ?? []));
            if (!in_array($code, $codes, true)) {
                continue;
            }

            $to = array_key_exists('to', $rule) && $rule['to'] !== null ? (float) $rule['to'] : null;

            return new StepDownPlan(
                amount: $to,
                delayDays: (int) ($rule['after_days'] ?? 1),
                midStrategy: $rule['mid'] ?? 'sticky',
                rule: $rule,
            );
        }

        return null;
    }
}
