<?php

/**
 * EXAMPLE — copy the relevant bits into a vertical's app service provider
 * (e.g. App\Providers\BillingServiceProvider) and register it.
 *
 * This is where the app glues its existing code to the engine's contracts.
 */

namespace App\Providers;

use App\Library\NMI;                       // or App\Library\Inovio
use App\Models\OmniTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Registry\BillingHandlerRegistry;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1. The concrete gateway library instance the adapter wraps.
        $this->app->bind('billing.gatewayClient', function () {
            $nmi = new NMI();
            $nmi->setApiKey(config('nmi.api_key'));
            return $nmi;
        });

        // 2. (Optional) use the mid-balancer for MID decisions instead of the
        //    default direct-table resolver.
        $this->app->singleton(MidResolver::class, \App\Billing\SportsMidBalancerAdapter::class);

        // 3. Guard closures — keep vertical-specific SQL in the app.
        $this->app->instance('billing.negativeDb', function (string $memberId): ?string {
            // return a reason string to hard-stop, or null to pass
            return null; // ... your negativeDb() logic
        });

        $this->app->instance('billing.sameDayCheck', function (string $memberId): bool {
            return OmniTransaction::where('cust_id_ext', $memberId)
                ->where('resp_id', 0)
                ->where('tr_date_only', now('MST7MDT')->toDateString())
                ->exists();
        });

        $this->app->instance('billing.conversionRebill', fn (string $memberId): bool => false);

        $this->app->instance('billing.declineCount', function (string $memberId, string $type): int {
            return OmniTransaction::where('cust_id_ext', $memberId)
                ->where('resp_id', '!=', 0)->where('tr_amount', '>', 2)->count();
        });

        // 4. Legacy log-table name resolver (dual-write target). The unified
        //    billing_attempts_{vertical} table is written automatically; this
        //    closure tells the LEGACY logger which per-type/cross table to also
        //    write, including cross-product naming decided at runtime.
        $this->app->instance('billing.logTable', function (\Omni\BillingEngine\Support\BillingContext $ctx): string {
            return match ($ctx->billingType) {
                'rebill'  => 'rebill_sports',
                'cross1'  => 'rebill_games_sports',
                'cross2'  => 'rebill_vod_sports',
                'convert' => 'conversion_sports',
                'settle'  => 'settle_sports',
                // cross-product example: rebill_{product}_{stack}
                default   => 'rebill_' . ($ctx->row->meta['cross_target'] ?? 'sports') . '_sports',
            };
        });

        // 5. Backfill source generator (see SeedSourceProvider.php).
        $this->app->instance('billing.seedSource', new \App\Billing\SeedSourceProvider());
    }

    public function boot(): void
    {
        // 6. Override a handler for a type that needs custom behaviour.
        $this->app->make(BillingHandlerRegistry::class)
            ->bind('cross2', \App\Billing\CustomCrossHandler::class);
    }
}
