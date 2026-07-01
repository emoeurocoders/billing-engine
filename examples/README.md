# Wiring billing-engine into a vertical

Five things to wire per vertical. All of it lives in the **app**, not the package.

1. **Publish + tune config** — `php artisan vendor:publish --tag=billing-engine-config`
   then edit `config/billing-engine.php` (see `billing-engine.config.example.php`):
   - `vertical`, `gateway.driver` (`nmi`|`inovio`)
   - `mids.table` (`mids` / `pdf_mids` / `manuals_mids`)
   - `schedule.table`, per-`types` amounts/UDFs/cycle/guards/stepdowns

2. **Migrate** — `php artisan vendor:publish --tag=billing-engine-migrations && php artisan migrate`
   creates `billing_schedule_{vertical}`.

3. **Bind the integrations** in a service provider (`AppServiceProviderWiring.php`):
   - `billing.gatewayClient` → your `App\Library\NMI` / `App\Library\Inovio` instance
   - (optional) `MidResolver` → `SportsMidBalancerAdapter` to use the load-balancer
   - the guard closures (`billing.negativeDb`, `billing.sameDayCheck`, …)
   - `billing.seedSource` → the backfill generator (`SeedSourceProvider.php`)

4. **Seed + verify** — `php artisan billing:seed-schedule sports --dry-run`,
   compare the `pending` count to the legacy population, then run for real and
   shadow one cycle.

5. **Schedule the dispatcher** (`KernelSchedule.php`) and run queue workers.
   Cut the legacy `nmi:billing rebill*` schedule lines only after parity holds.

## Customising behaviour

- **Data difference** (amount, UDF, cycle, stepdown ladder, MID table) → **config only**.
- **Flow difference** → subclass a handler hook (`CustomCrossHandler.php`) and
  `BillingHandlerRegistry::bind('cross2', ...)`.
- **New rule** → implement `BillingGuard` (`CustomGuard.php`), register it, add its
  key to the type's `guards` array.
- **Side-effect** (receipt, cross-sell, telemetry) → listen for `BillingSucceeded` /
  `BillingDeclined` etc.
