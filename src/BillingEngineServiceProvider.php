<?php

namespace Omni\BillingEngine;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Omni\BillingEngine\Console\Commands\DispatchCommand;
use Omni\BillingEngine\Console\Commands\SeedScheduleCommand;
use Omni\BillingEngine\Logging\BillingLogSubscriber;
use Omni\BillingEngine\Cards\NullCardVault;
use Omni\BillingEngine\Contracts\AttemptLogger;
use Omni\BillingEngine\Contracts\CardVault;
use Omni\BillingEngine\Contracts\GatewayClient;
use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Gateways\InovioGateway;
use Omni\BillingEngine\Gateways\NmiGateway;
use Omni\BillingEngine\Guards\AlreadyAttempted;
use Omni\BillingEngine\Guards\ConversionRebill;
use Omni\BillingEngine\Guards\MaxDeclines;
use Omni\BillingEngine\Guards\MidCap;
use Omni\BillingEngine\Guards\NegativeDb;
use Omni\BillingEngine\Guards\SameDay;
use Omni\BillingEngine\Loggers\BillingAttemptsLogger;
use Omni\BillingEngine\Loggers\BillingEventLogger;
use Omni\BillingEngine\Loggers\DualAttemptLogger;
use Omni\BillingEngine\Loggers\LogTableAttemptLogger;
use Omni\BillingEngine\Pipeline\GuardRunner;
use Omni\BillingEngine\Registry\BillingHandlerRegistry;
use Omni\BillingEngine\Resolvers\DirectMidResolver;

class BillingEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/billing-engine.php', 'billing-engine');

        // Default contract bindings — a vertical overrides any of these in its
        // own provider (e.g. bind its own MidResolver / gateway client).
        $this->app->singleton(MidResolver::class, DirectMidResolver::class);

        // Stored-card ("AZ") vault. Default = none (everyone rebills the token);
        // sports binds its own SportsCardVault (VodTokens + Vault + Crypt).
        $this->app->singleton(CardVault::class, NullCardVault::class);

        // Attempt logging. By default we DUAL-WRITE the engine-owned unified
        // table + the legacy per-type tables, reading from `log.read_from`.
        // Flip read_from to 'unified' (and disable legacy) once it has history.
        $this->app->singleton(AttemptLogger::class, function ($app) {
            $unified = $app->make(BillingAttemptsLogger::class);
            $legacy  = $app->make(LogTableAttemptLogger::class);

            if (!config('billing-engine.log.dual_write', true)) {
                return config('billing-engine.log.read_from', 'legacy') === 'unified' ? $unified : $legacy;
            }

            $writers = config('billing-engine.log.legacy', true) ? [$unified, $legacy] : [$unified];
            $reader  = config('billing-engine.log.read_from', 'legacy') === 'unified' ? $unified : $legacy;

            return new DualAttemptLogger($writers, $reader);
        });

        $this->app->singleton(GatewayClient::class, function ($app) {
            // The app must provide the concrete gateway library instance via
            // 'billing.gatewayClient'; we only wrap it in the right adapter.
            $driver = config('billing-engine.gateway.driver', 'nmi');
            $client = $app->bound('billing.gatewayClient') ? $app->make('billing.gatewayClient') : null;

            if (!$client) {
                throw new \RuntimeException(
                    "Bind 'billing.gatewayClient' to your NMI/Inovio library instance. See examples/AppServiceProviderWiring.php."
                );
            }

            return $driver === 'inovio' ? new InovioGateway($client) : new NmiGateway($client);
        });

        // Handler registry seeded from config (verticals re-bind per type).
        $this->app->singleton(BillingHandlerRegistry::class, function ($app) {
            return new BillingHandlerRegistry($app, config('billing-engine.types', []));
        });

        // Guard pipeline with the default guard set registered by key.
        $this->app->singleton(GuardRunner::class, function ($app) {
            $runner = new GuardRunner($app);
            $runner->register('already_attempted', AlreadyAttempted::class);
            $runner->register('same_day', SameDay::class);
            $runner->register('negative_db', NegativeDb::class);
            $runner->register('max_declines', MaxDeclines::class);
            $runner->register('mid_cap', MidCap::class);
            $runner->register('conversion_rebill', ConversionRebill::class);
            return $runner;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchCommand::class,
                SeedScheduleCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/billing-engine.php' => config_path('billing-engine.php'),
            ], 'billing-engine-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'billing-engine-migrations');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Read-only rebill status dashboard. Views always available for override;
        // routes load ONLY when the dashboard is enabled (same as mid-balancer).
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'billing-engine');

        if (config('billing-engine.dashboard.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/billing-engine'),
            ], 'billing-engine-views');
        }

        // Full per-action audit trail (SUCCESS/DECLINE/SKIP/DEAD/STEPDOWN) to the
        // configured log channel — the replacement for the legacy log() file.
        if (config('billing-engine.logging.enabled', true)) {
            Event::subscribe(BillingLogSubscriber::class);
        }

        // Durable SKIP/DEAD rows in billing_events_{vertical}. Separate from the
        // audit log above: that writes text to a file, this writes queryable rows
        // so reporting can show skips for ANY past day. Writes are guarded.
        if (config('billing-engine.events.enabled', true)) {
            Event::subscribe(BillingEventLogger::class);
        }
    }
}
