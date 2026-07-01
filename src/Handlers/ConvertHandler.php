<?php

namespace Omni\BillingEngine\Handlers;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GatewayResult;
use Omni\BillingEngine\Support\MidDecision;

/**
 * Initial/trial conversions (convertInitials, convertPP). Selection is by
 * rotation (config: selection=rotation) rather than sticky, so it leans on
 * MidResolver::resolveRotationMid — which a vertical can back with the
 * mid-balancer's load-balancer via its adapter.
 */
class ConvertHandler extends BillingHandler
{
    protected function resolveMid(BillingContext $ctx): ?MidDecision
    {
        return $this->mids->resolveRotationMid($ctx);
    }

    protected function charge(BillingContext $ctx, array $payload): GatewayResult
    {
        // Conversions historically used doCharge; default to rebill() when no
        // explicit card data is present so token-based flows still work.
        return isset($payload['card_number'])
            ? $this->gateway->charge($payload)
            : $this->gateway->rebill($payload);
    }
}
