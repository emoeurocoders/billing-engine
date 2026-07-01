# Architecture

## The problem this replaces

Each vertical runs its own ~10,000-line `NMIBilling` / `InovioBilling` Artisan command.
Billing runs as **scheduled commands** that each loop over up to 4,500 rows pulled
straight from the transaction ledger and charge them synchronously, one process, with a
`sleep()` between rows. The known failure modes:

1. **`withoutOverlapping()` 24h lock.** A run that touches thousands of rows takes 20–40
   min; if it crashes/OOMs/hangs, the mutex isn't released and **every subsequent run is
   silently skipped for up to 24h.** This is the primary "rebills not processed" cause.
2. **No error isolation.** One gateway timeout or unset `response_code` throws and kills
   the rest of the batch.
3. **`ensureDailyStats()` called inside the loop** — re-loads all active MIDs and upserts
   per-MID daily stats on every one of up to 4,500 iterations.
4. **The ledger is used as a queue.** `auth_transactions_{vertical}` (5.1M rows on sports)
   is mutated in place — `bat_date` as a cursor, `k1` as a dead-flag — and "due" is
   reconstructed every run from 30-day date math + per-row log probes.
5. **Five near-identical sticky-rebill methods** (`rebillCC/PP/Settles/Cross1/Cross2`) —
   ~1,000 duplicated lines × 6 verticals.

## The three-database context

| DB | Role | Tables the engine touches |
|---|---|---|
| `bigentertainment` (default) | **MID config + runtime state** | `mids` / `pdf_mids` / `manuals_mids` (config), mid-balancer `rebills_daily_stats` (state, via adapter) |
| `omnistats` | **Legacy ledger + attempt logs** | `auth_transactions_*` (read at seed time), `rebill_*` / `conversion_*` / `settle_*` (attempt log) |
| this package | **Schedule** | `billing_schedule_{vertical}` (new source of truth) |

A legacy `UpdateMids` job syncs `bigentertainment.mids → omnistats.*_mids` only because
the two DBs used to live on separate servers. The engine reads MID config from the source
of truth and lets that sync be retired per vertical after cutover.

## The method families (8 methods → 3 shapes)

| Family | Legacy methods | Engine type | Selection | Charge |
|---|---|---|---|---|
| Sticky rebill | `rebillCC`, `rebillPP`, `rebillSettles`, `rebillCross1`, `rebillCross2` | `rebill`, `cross1`, `cross2` | sticky + redirect | `doRebill`/`doCharge` |
| Scheduled cross | `cross1`, `cross2` | `cross1`, `cross2` | sticky | `doRebill`/`doCharge` |
| Conversion / capture | `convertInitials`, `convertPP`, `settleAuths` | `convert`, `settle` | rotation / sticky | `doCharge` / `doCapture` |

The sticky-rebill family is five methods that differ only in UDF filter, counter table,
and log table — collapsed into one handler + config here.

## The pipeline

```
 billing_schedule_{vertical}
        │  ▲
  claim │  │ seed (one-time backfill)
        ▼  │
  billing:dispatch        scheduled every 1–2 min; short lock
        │                 - claims due rows (atomic UPDATE)
        │                 - dispatches one job per row
        ▼                 - exits in seconds
  ProcessBillingJob       queued, isolated, retryable, per-MID throttle
        │
        ▼
  BillingHandler.handle()  (final template method)
     1. guards        → GuardRunner (config-ordered pipeline)
     2. resolve MID   → MidResolver (sticky | rotation)
     3. build payload → hook (canonical keys)
     4. charge        → GatewayClient (NMI | Inovio) → GatewayResult
     5. record        → MidResolver::recordResult + AttemptLogger
     6. schedule next → hook (cycle advance | step-down | dead)
```

Compare with the legacy model — one synchronous command looping rows with a global
`sleep` — and the wins are direct: failures isolate to one job, throughput scales with
workers, retries/backoff are first-class, and a crashed run can never wedge the queue.

## Design principles

1. **The schedule table is the source of truth**, not the ledger. Explicit `next_action_at`,
   explicit `status`, unique idempotency key.
2. **Standalone package, ports & adapters.** The engine depends on no other package. Every
   integration point (MID source, gateway, attempt log, vertical SQL in guards/seeder) is a
   contract or an app-bound closure. The app — not the package — wires concrete code.
3. **One flow, many configurations.** A single `final handle()` defines the billing flow;
   variance is expressed by the lightest mechanism that fits (config → handler hook → guard
   → event → contract). See [extending.md](extending.md).
4. **Idempotency is structural.** `UNIQUE(idempotency_key)` on `{member}:{type}:{cycle}`
   makes a double charge impossible at the database level, not dependent on probe queries.
5. **Migrate one vertical at a time.** The legacy command keeps running untouched for every
   not-yet-migrated vertical; nothing is rewritten in place.

## Where the code lives

```
src/
  BillingEngineServiceProvider.php   bindings, commands, publishing
  Console/Commands/                  DispatchCommand, SeedScheduleCommand
  Jobs/ProcessBillingJob.php         one member per job
  Handlers/                          BillingHandler + Rebill/Cross/Convert/Settle
  Guards/ + Pipeline/GuardRunner     the rule pipeline
  Resolvers/DirectMidResolver        default MID source
  Gateways/                          NmiGateway, InovioGateway
  Loggers/LogTableAttemptLogger      default attempt log
  Registry/BillingHandlerRegistry    type → handler map
  Contracts/                         MidResolver, GatewayClient, AttemptLogger, BillingGuard
  Support/                           BillingContext, MidDecision, GatewayResult, GuardResult
  Events/                            the five lifecycle events
```
