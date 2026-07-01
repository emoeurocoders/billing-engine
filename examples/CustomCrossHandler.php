<?php

/**
 * EXAMPLE — a vertical whose cross step-down needs different LOGIC than the
 * config-driven ladder can express. Most verticals only change numbers (config
 * `stepdowns` rules); subclass ONLY when the behaviour itself differs.
 *
 * Register with:
 *   BillingHandlerRegistry::bind('cross2', CustomCrossHandler::class);
 */

namespace App\Billing;

use Omni\BillingEngine\Handlers\CrossHandler;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\StepDownPlan;

class CustomCrossHandler extends CrossHandler
{
    /**
     * Example: when stepping down, also stamp a custom marker and clamp the
     * delay. The base applyStepDown handles amount/MID/next_action_at; here we
     * tweak the plan's effect before delegating.
     */
    protected function applyStepDown(BillingContext $ctx, StepDownPlan $plan): void
    {
        // e.g. record which rung we landed on for this vertical's reporting
        $ctx->row->meta = array_merge($ctx->row->meta ?? [], [
            'stepdown_rung' => ($ctx->row->step ?? 0) + 1,
        ]);

        parent::applyStepDown($ctx, $plan);
    }
}
