# Billing Engine


Laravel package for scheduled card-billing across verticals — rebills, cross-rebills,
conversions, and auth settlements. It replaces the fragile, duplicated `NMIBilling` /
`InovioBilling` command loops that bill directly off the raw transaction ledger with a
proper queue-driven pipeline: a fast dispatcher that claims due work, and isolated,
retryable jobs that each bill one member.

The engine is **gateway-agnostic** (NMI and Inovio behind one contract) and
**vertical-agnostic** (one package installed in all 6 verticals; per-vertical differences
expressed through config, handlers, guards, events, and adapters — never forks).

- **`rebill`** — standard sticky monthly rebill (replaces `rebillCC` / `rebillPP`)
- **`cross1` / `cross2`** — cross-product rebills with step-down ladders
- **`convert`** — initial/trial conversions (rotation-selected MID)
- **`settle`** — capture an auth-only transaction N days later (`settleAuths`)

All types share the same dispatcher, job, schedule table, guard pipeline, and recording
path — they differ only in a handler hook or two and their config block.

## Requirements

- PHP 8.0+
- Laravel 9, 10, or 11
- A queue connection (`database` recommended to start; Redis/Horizon optional later)

## Installation

Add the package as a path repository in the vertical's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "/path/to/billing-engine", "options": { "symlink": true } }
    ],
    "require": { "omni/billing-engine": "@dev" }
}
```

```bash
composer update omni/billing-engine
```

The service provider is auto-discovered. Publish config and migrations, then migrate:

```bash
php artisan vendor:publish --tag=billing-engine-config
php artisan vendor:publish --tag=billing-engine-migrations
php artisan migrate          # creates billing_schedule_{vertical}
```

## How It Works

### The pipeline

```
  billing_schedule_{vertical}  ── source of truth: due dates, status, idempotency
            │  ▲
   claim    │  │ seed once (billing:seed-schedule)
 (atomic)   ▼  │
  billing:dispatch  ── every 1–2 min, short lock, claims a batch, fans out jobs, exits
            │
            ▼  one job per member
  ProcessBillingJob  ── isolated, retryable
            │
            ▼
  BillingHandler.handle()
     guards → resolve MID → build payload → charge → record → schedule next
        │        │             │             │         │           │
      Guard    MidResolver   (hook)      GatewayClient  AttemptLogger + MID state
    pipeline                            (NMI / Inovio)
```

The dispatcher never charges — it only **claims** due rows (`status=pending AND
next_action_at<=now` → `claimed`) and dispatches one job each, then exits in seconds.
There is no long-held lock and no in-process loop, which is what eliminates the legacy
"one crash wedges all rebills for 24h" failure mode.

### Row lifecycle

Every schedule row moves through a small state machine:

```
            ┌────────── retry next cycle (skip) ──────────┐
            ▼                                              │
  pending ──claim──▶ claimed ──charge──▶ approved ──▶ done
            │            │                  │
            │            │ decline          └─ declined ─▶ pending (retry)
            │            ▼
            │      job failure ─▶ pending (claim released)
            ▼
  guard DEAD / max declines / negative-db ─▶ dead   (never retried)
```

- **pending** — due (or will be) and eligible to claim
- **claimed** — picked up by a dispatch run; only the owning job acts on it
- **done** — billed this cycle; `next_action_at` advanced to next cycle
- **dead** — permanently stopped (hard decline, cancellation, max declines)
- **skipped** — transiently deferred to the next cycle (already attempted, same-day, etc.)

### Why a dedicated schedule table

The legacy code treated the 5M-row `auth_transactions_{vertical}` ledger as a queue —
mutating `bat_date`/`k1` columns in place and reconstructing "who is due" every run from
date math and per-row log probes. The engine instead **materialises** a purpose-built
`billing_schedule_{vertical}` table with an explicit `next_action_at` and a
`UNIQUE(idempotency_key)`, so:

- "due?" is a single indexed predicate, not a scan + date math;
- double-billing is **structurally impossible** (unique key), not probe-dependent;
- the ledger goes back to being read-only analytics.

## Basic Usage

Once a vertical is wired (see [Wiring a vertical](#wiring-a-vertical)), operation is two
scheduled pieces:

```php
// app/Console/Kernel.php
$schedule->command('billing:dispatch')
    ->everyMinute()
    ->withoutOverlapping(2)   // short auto-expiring lock — NOT the legacy 24h default
    ->runInBackground();
```

```bash
# queue workers
php artisan queue:work database --queue=billing --tries=3 --backoff=60,300,900
```

Seed the schedule table once from the legacy ledger, verifying before committing:

```bash
php artisan billing:seed-schedule sports --dry-run   # report only
php artisan billing:seed-schedule sports             # write (idempotent, re-runnable)
```

### Previewing a run before charging anyone (`--dry-run`)

Before you start the workers and charge real cards, preview **exactly who would be
billed** this dispatch. `billing:dispatch --dry-run` walks every due row through the
**real guard pipeline and MID resolution** and stops one step before the gateway charge —
it claims nothing, charges nothing, and writes nothing:

```bash
# Who would the next rebill dispatch charge? (read-only, safe on live data)
php artisan billing:dispatch --type=rebill --dry-run

# Same, but export the full per-member list for review / diffing against legacy
php artisan billing:dispatch --type=rebill --dry-run --out=storage/rebill-preview.csv
```

Sample output:

```
DRY RUN — no rows claimed, no charges sent, nothing written.

+-----------+--------+------+--------+------+--------------+----------+---------------------------+
| member    | type   | card | amount | step | disposition  | mid      | reason                    |
+-----------+--------+------+--------+------+--------------+----------+---------------------------+
| 100231    | rebill | cc   | 34.95  | 0    | CHARGE       | mid_07   |                           |
| 100244    | rebill | cc   | 34.95  | 0    | SKIP         | —        | already_attempted_declined|
| 100250    | rebill | pp   | 34.95  | 0    | DEAD         | —        | max_declines              |
| 100261    | rebill | cc   | 19.55  | 1    | CHARGE       | mid_12   |                           |
+-----------+--------+------+--------+------+--------------+----------+---------------------------+
  … showing first 25 of 4182 due rows. Use --out for the full list.

+--------------------------+---------+----------+
| disposition              | members | total $  |
+--------------------------+---------+----------+
| WOULD CHARGE             | 3901    | 132847.95|
| would skip               | 214     | —        |
| would be marked dead     | 51      | —        |
| no usable MID (deferred) | 16      | —        |
+--------------------------+---------+----------+

3901 members would be charged a total of 132847.95. Nothing was charged or written.
```

The recommended cutover sequence: seed → `--dry-run` and eyeball the CHARGE count and total
against the legacy population → only then start the workers and schedule the dispatcher.
See [docs/migrating-a-vertical.md](docs/migrating-a-vertical.md).

## Wiring a vertical

All integration lives in the **app**, not the package. Five steps (full copy-paste
examples in [`examples/`](examples/)):

1. **Config** — publish and tune `config/billing-engine.php`: `vertical`,
   `gateway.driver`, `mids.table`, per-`types` settings.
2. **Migrate** — `billing_schedule_{vertical}`.
3. **Bind integrations** in a service provider:
   - `billing.gatewayClient` → your `App\Library\NMI` / `App\Library\Inovio` instance
   - (optional) `MidResolver` → an adapter onto mid-balancer
   - guard closures (`billing.negativeDb`, `billing.sameDayCheck`, …)
   - `billing.seedSource` → the backfill generator
4. **Seed + verify** — `billing:seed-schedule --dry-run`, compare to the legacy
   population, then run for real and shadow one cycle.
5. **Schedule** the dispatcher and start workers; remove the legacy `nmi:billing rebill*`
   schedule lines only after parity holds.

## Configuration

`config/billing-engine.php` is where the bulk of per-vertical variance lives. Key blocks:

| Key | Purpose |
|---|---|
| `vertical` | stack name; drives the schedule table and `stack` written to MID state |
| `schedule.{connection,table}` | per-vertical schedule table (`billing_schedule_{vertical}`) |
| `mids.{connection,table}` | MID config source: `mids` / `pdf_mids` / `manuals_mids` (all same shape, on `bigentertainment`) |
| `gateway.driver` | `nmi` or `inovio` |
| `queue.{connection,name,tries,backoff}` | job queue + retry policy |
| `dispatch.{claim_batch,lock_seconds,per_mid_throttle}` | dispatcher tuning |
| `cycle_days` | default billing cycle (per-type override allowed) |
| `types.{type}` | handler, selection mode, UDFs, guards, log table, stepdown ladder |

See [`docs/configuration.md`](docs/configuration.md) for every key.

## Extending per vertical

Match each kind of difference to the lightest mechanism:

| Difference | Mechanism |
|---|---|
| amount, UDF, cycle, MID table, stepdown ladder, decline map | **config** |
| flow behaviour (e.g. a different step-down rule) | subclass a **handler** hook + `BillingHandlerRegistry::bind()` |
| a new pre-charge rule | implement **`BillingGuard`**, register, add its key to the type's `guards` |
| a side-effect (receipt, cross-sell, telemetry) | listen for a **billing event** |
| swap MID source / gateway / attempt log | bind a **contract** implementation |

Full guide: [`docs/extending.md`](docs/extending.md).

## Commands

| Command | Purpose |
|---|---|
| `billing:dispatch [--type=*] [--limit=]` | claim due rows and dispatch jobs (scheduled) |
| `billing:dispatch --dry-run [--type=*] [--out=file.csv]` | preview who would be charged — claims/charges/writes nothing |
| `billing:seed-schedule {vertical} [--type=*] [--dry-run] [--spread-hours=]` | backfill the schedule table from the legacy ledger |

## Contracts

The package depends on **no other package** (not even mid-balancer). Integration is
through four small interfaces the app binds:

| Contract | Default impl | Override to… |
|---|---|---|
| `MidResolver` | `DirectMidResolver` (reads configured mids table) | use mid-balancer / a custom pool |
| `GatewayClient` | `NmiGateway` / `InovioGateway` | add a new processor |
| `AttemptLogger` | `DualAttemptLogger` (unified `billing_attempts_*` + legacy tables) | change/replace the attempt log |
| `BillingGuard` | 6 built-in guards | add vertical rules |

## Database Tables

| Table | Owner | Notes |
|---|---|---|
| `billing_schedule_{vertical}` | this package (migration) | source of truth (one per vertical) |
| `billing_attempts_{vertical}` | this package (migration) | unified attempt log; replaces the ~50 legacy `rebill_*` tables |
| `mids` / `pdf_mids` / `manuals_mids` | existing (`bigentertainment`) | MID config, read-only here |
| `rebill_*` / `conversion_*` / `settle_*` | existing (`omnistats`) | legacy attempt logs; **dual-written** during migration |

The engine ships **two** migrations (schedule + unified attempt log). During migration it
dual-writes the unified table *and* the legacy tables, reading from whichever
`log.read_from` names — flip to the unified table and drop the legacy ones once it has
history. See [docs/data-model.md](docs/data-model.md).

## Step-downs

Declined attempts step down to a lower amount (and optionally a different MID) after a
delay, driven by **decline-code config** per billing type — covering the legacy
`rebillStepDown*` / `stepDownCC` crons. The ladder lives in each type's `stepdowns` block;
the engine handles it inside the schedule row's decline lifecycle, no separate cron. See
[`docs/step-downs.md`](docs/step-downs.md).

## Events

`BillingAttempting`, `BillingSucceeded`, `BillingDeclined`, `BillingSkipped`,
`BillingDead`, `BillingSteppedDown`. See [`docs/events.md`](docs/events.md).

## Documentation

In-depth docs live in [`docs/`](docs/):

- [Architecture](docs/architecture.md) — the big picture and why
- [Data model](docs/data-model.md) — schedule table, statuses, idempotency
- [Handlers](docs/handlers.md) — the template method and the four handlers
- [Guards](docs/guards.md) — the pipeline and built-in guards
- [Step-downs](docs/step-downs.md) — decline-code-driven step-down ladders
- [MID resolution](docs/mid-resolution.md) — resolver contract, configurable source, mid-balancer adapter
- [Gateways](docs/gateways.md) — NMI/Inovio adapters, canonical payload, normalised result
- [Dispatcher & jobs](docs/dispatcher-and-jobs.md) — claim mechanism, queue, retries
- [Backfill](docs/backfill.md) — the seeder, source provider, parity gate
- [Configuration](docs/configuration.md) — every config key
- [Extending](docs/extending.md) — the layered extensibility model
- [Migrating a vertical](docs/migrating-a-vertical.md) — step-by-step cutover
- [Events](docs/events.md) — event reference

## Status

Skeleton — lint-clean and structurally complete, not yet wired into a running app.
Per-vertical wiring (gateway client, guard closures, seed source) is supplied by the app;
see [`examples/`](examples/). See [`PLAN.md`](PLAN.md) for the full design rationale and
rollout plan.
