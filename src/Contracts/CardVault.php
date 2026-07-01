<?php

namespace Omni\BillingEngine\Contracts;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\CardData;

/**
 * Resolves a member's stored ("AZ") card so the handler can charge via the
 * gateway's card path (doCharge) instead of the token rebill (doRebill) —
 * the legacy getAzKey() seam.
 *
 * This is inherently vertical/app specific (VodTokens + Vault + Crypt on sports),
 * so the package ships only NullCardVault (always null → token rebill). A vertical
 * that stores cards binds its own implementation:
 *
 *   $this->app->singleton(CardVault::class, \App\Billing\SportsCardVault::class);
 *
 * Return null whenever there's no usable stored card — the handler then falls back
 * to the token rebill path, exactly as the legacy `if($az){...}else{...}` did.
 */
interface CardVault
{
    public function resolve(BillingContext $ctx): ?CardData;
}
