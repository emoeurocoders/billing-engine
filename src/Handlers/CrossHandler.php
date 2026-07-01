<?php

namespace Omni\BillingEngine\Handlers;

/**
 * Cross-rebills (cross1/cross2). Structurally a sticky rebill — the only
 * difference from `rebill` is its step-down ladder and "cancel when the ladder
 * is exhausted" behaviour, both of which are now **config**, handled uniformly
 * by the base handler + StepDownPlanner:
 *
 *   'cross2' => [
 *       'stepdowns' => [
 *           'max_steps'    => 1,
 *           'on_exhausted' => 'dead',   // cancel instead of waiting next cycle
 *           'rules' => [ ['step'=>0,'codes'=>['104','105','109','157'],'after_days'=>10,'to'=>19.79] ],
 *       ],
 *   ]
 *
 * A vertical that needs different step-down *logic* (not just numbers) subclasses
 * and overrides a hook — see examples/CustomCrossHandler.php.
 */
class CrossHandler extends BillingHandler
{
    // Behaviour is fully config-driven via the base handler.
}
