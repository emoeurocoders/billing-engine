<?php

namespace Omni\BillingEngine\Handlers;

/**
 * Standard sticky monthly rebill. Uses every default in BillingHandler:
 * sticky MID, rebill() charge, next cycle on approval. This single handler
 * covers what rebillCC, rebillPP and rebillSettles' sale path used to do —
 * card type and amount come from the schedule row, UDFs from config.
 */
class RebillHandler extends BillingHandler
{
    // Intentionally empty: the base flow is the rebill flow.
}
