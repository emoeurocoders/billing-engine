# Migrating a vertical

How to move one vertical from its legacy `NMIBilling` / `InovioBilling` command to the
engine. The legacy command keeps running untouched until the very last step, and each
vertical migrates on its own timeline.

## Prerequisites

- The package is installed and lint-clean in the vertical's app.
- You know the vertical's gateway (`nmi`/`inovio`), MID table (`mids`/`pdf_mids`/…), the
  billing types in use, and their amounts/UDFs/log tables.

## Step 1 — Config

Publish and fill in `config/billing-engine.php`:

```php
'vertical' => 'pdf',
'gateway'  => ['driver' => 'inovio'],
'mids'     => ['table' => 'pdf_mids'],
'schedule' => ['table' => 'billing_schedule_pdf'],
'types'    => [ /* rebill/cross/convert/settle blocks */ ],
```

Use `examples/billing-engine.config.example.php` as the template.

## Step 2 — Migrate the schedule table

```bash
php artisan vendor:publish --tag=billing-engine-migrations
php artisan migrate    # creates billing_schedule_{vertical}
```

## Step 3 — Wire integrations

In a `BillingServiceProvider` (see `examples/AppServiceProviderWiring.php`), bind:

- `billing.gatewayClient` → the app's `NMI`/`Inovio` instance
- `MidResolver` → `DirectMidResolver` (default) or a mid-balancer adapter
- the guard closures used by this vertical's guard chains
- `billing.seedSource` → the backfill generator with this vertical's ledger queries
- any custom handlers (`BillingHandlerRegistry::bind`) and custom guards

## Step 4 — Seed and check parity (dry run)

```bash
php artisan billing:seed-schedule pdf --dry-run
```

Compare the printed `pending` count to the population the legacy query selects right now.
Investigate any material gap (wrong UDF set, missing dead-flag handling, cycle mismatch)
before writing anything.

## Step 5 — Seed for real

```bash
php artisan billing:seed-schedule pdf
```

Idempotent — safe to re-run. Spot-check a handful of members: correct `mid_id`, sane
`next_action_at`, right `amount`, `deferred` for anyone billed this cycle.

## Step 6 — Preview the first dispatch (dry run)

Before any worker runs, preview **exactly who the next dispatch would charge** — read-only,
no claim, no charge, no write:

```bash
php artisan billing:dispatch --type=rebill --dry-run --out=storage/rebill-preview.csv
```

Check the **WOULD CHARGE** count and dollar total against what the legacy `rebillCC` /
`rebillPP` run would bill today, and skim the skip/dead reasons for anything unexpected
(everyone already billed this cycle should land in SKIP/DEAD, never CHARGE). The CSV is the
artifact to diff against the legacy population. Only proceed once the preview matches. See
[dispatcher-and-jobs.md](dispatcher-and-jobs.md#billingdispatch---dry-run--preview-before-charging).

## Step 7 — Shadow one cycle

Run dispatch + workers **without retiring the legacy schedule**, in a comparison mode:

- Easiest: keep the legacy command as the system of record, run the engine with charging
  short-circuited (e.g. a no-op `GatewayClient` in this vertical) and compare which members
  each would bill, day by day, for a full cycle.
- Or run the engine live on a small `--type`/segment and reconcile against the legacy logs.

The goal: confirm the engine bills the same population, same amounts, same cadence, with no
double-charges.

## Step 8 — Cut over

1. Point the gateway binding at the real `GatewayClient`.
2. Schedule the dispatcher and start workers:

   ```php
   $schedule->command('billing:dispatch')->everyMinute()->withoutOverlapping(2)->runInBackground();
   ```
   ```bash
   php artisan queue:work database --queue=billing --tries=3 --backoff=60,300,900
   ```

3. **Remove the legacy schedule lines** for this vertical's rebills from `Kernel.php`
   (`nmi:billing rebillCC`, `rebillPP`, `rebillSettles`, `rebillCross1`, `rebillCross2`).
   Leave any not-yet-migrated types on the legacy command.

## Step 9 — Retire the omnistats MID sync (per vertical)

Once nothing reads the vertical's `*_mids` counter table:

- Stop the `UpdateMids` sync for that vertical.
- Remove legacy counter reads/writes.

MID config now comes from `bigentertainment.{mids|pdf_mids|manuals_mids}` and runtime state
from the resolver (mid-balancer or default).

## Rollback

Because the legacy command is untouched, rollback is: re-enable its schedule lines and stop
`billing:dispatch`. The schedule table can stay populated; re-seed/clean later. Nothing in
the ledger was mutated by the engine at runtime.

## Per-type phasing within a vertical

You don't have to migrate all types at once. A safe order:

1. `rebill` (highest volume, clearest parity)
2. `cross1` / `cross2`
3. `settle`
4. `convert`

Keep the un-migrated types on the legacy command until each is verified.
