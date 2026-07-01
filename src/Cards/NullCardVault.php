<?php

namespace Omni\BillingEngine\Cards;

use Omni\BillingEngine\Contracts\CardVault;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\CardData;

/**
 * Default CardVault: no stored cards. Every member goes through the token rebill
 * path (doRebill). Verticals that don't use the AZ/stored-card flow use this
 * as-is; sports binds its own SportsCardVault. Also the effective behaviour when
 * `billing-engine.az.enabled` is false.
 */
class NullCardVault implements CardVault
{
    public function resolve(BillingContext $ctx): ?CardData
    {
        return null;
    }
}
