# Backfill (`billing:seed-schedule`)

The legacy code never stored "who is due" — it reconstructed it every run from date math
and log probes. To move to a real schedule table we must **materialise** that population
once, correctly, without double-billing anyone mid-cycle. That's what the seeder does.

```
billing:seed-schedule {vertical} [--type=rebill ...] [--dry-run] [--spread-hours=6]
```

It is **re-runnable** (idempotent via the unique key) and **streamed** (never loads the 5M
ledger into memory).

## Division of labour

| Concern | Owner |
|---|---|
| *Which* ledger rows are candidates (vertical SQL) | the app — `billing.seedSource` generator |
| Dedup to one row per member/type, cycle math, idempotency, backlog spread, insert, parity | the package — `SeedScheduleCommand` |

This keeps every vertical's schema out of the package while the tricky materialisation logic
is written and tested once.

## The seed-source generator

The app binds an invokable to `billing.seedSource`:

```php
($vertical, array $types) => iterable<array>
```

It must yield **one row per member per billing_type** (the member's latest success),
shaped:

```php
[
  'member_id'                 => '...',
  'billing_type'              => 'rebill',
  'card_type'                 => 'cc',
  'source_tr_id'              => '...',     // rebill token / capture ref
  'mid_id'                    => '...',     // sticky MID
  'amount'                    => 34.55,
  'anchor_date'               => '2026-05-20 12:00:00', // latest success → next_action_at base
  'already_billed_this_cycle' => false,     // reconcile vs the legacy attempt log
  'dead'                      => false,      // honor k1=2 / cancellations
  'meta'                      => [           // travels onto the schedule row (see below)
      'bin' => '414720', 'ip' => '1.2.3.4', 'country' => 'US',
      'device' => 'aff123', 'udf_1' => 'SPORTZ', 'card_exp' => '0427',
  ],
]
```

Use a streaming query (`->cursor()`). **Do not `GROUP BY` member** — under
`ONLY_FULL_GROUP_BY` (strict mode) it errors, and even where allowed the non-aggregated
columns come from an arbitrary row, not the `MAX(tr_date)` one. Instead order by
`member, tr_date DESC` and take the first row per member in PHP (`latestPerMember()` in the
example) — strict-mode safe and returns the member's actual latest row. Index
`(cust_id_ext, tr_date)` for speed on large tables.

### Where the source tables are configured (changing them per app)

The generator reads its ledger tables from the **`billing-engine.seed`** config block, not
hardcoded names — so wiring another app is a config change:

```php
'seed' => [
    'connection' => 'omnistats',
    'sources' => [
        'transactions' => 'auth_transactions_sports', // rebillCC / rebillPP ledger
        'tickets'      => 'auth_tickets_sports',       // rebillSettles source
        'attempts'     => 'rebill_sports',             // already-billed reconcile
        'auths'        => 'auth_only_sports',          // settle (capture) + convert enrolment
    ],
    'udf2'              => ['cc' => ['CCC', 'CCR'], 'pp' => ['PPC', 'PPR']],
    'settle_amounts'    => [34.55, 29.55, 19.55],
    'settle_after_days' => 2,       // settle capture: due = auth + N days
    'convert_days'      => 5,       // convert: due = auth + N days
    'convert_amount'    => 29.55,   // conversion charge amount
],
```

For another vertical, point these at its tables (e.g. `auth_transactions_pdf`,
`auth_tickets_pdf`, `rebill_pdf`, and its own UDF sets) and the same generator runs
unchanged. The example reads every one of these via `config('billing-engine.seed.*')` with
the sports values as fallbacks. Note: the package's `SeedScheduleCommand` still never touches
these tables — only the app generator does; the command only reads/writes the schedule table
(`billing-engine.schedule`).

### The three rebill populations

The sports `rebill` type is seeded from **three** legacy sources, all yielded as
`billing_type = 'rebill'` (one handler; only the selection differs):

| Legacy | Source table | Filter | `card_type` | amount |
|---|---|---|---|---|
| `rebillCC` | `auth_transactions_sports` | `tui_udf02 IN (CCC,CCR)` | `cc` | `tr_amount` |
| `rebillPP` | `auth_transactions_sports` | `tui_udf02 IN (PPC,PPR)` | `pp` | `tr_amount` |
| `rebillSettles` | `auth_tickets_sports` | `tr_amount IN (34.55, 29.55, 19.55)` | `cc` | the ticket amount |

They share one cycle key `{member}:rebill:{cycle}`, so a member appearing in more than one
source collapses to a single row (first-yielded wins via `insertOrIgnore`) — structurally
preventing a double charge across the old separate crons.

### What goes in `meta`

`meta` is stored on the schedule row and read at charge time, so the run needs **no
per-charge ledger hit** for these:

| key | used by |
|---|---|
| `bin` | `NegativeDb` bin-block check |
| `ip` / `country` / `device` | gateway payload (legacy `doRebill` fields) |
| `udf_1` | gateway payload |
| `card_exp` | AZ `doCharge` (stored-card path) |

Billing name/email/zip are intentionally left out (the `CardVault` falls back to the ledger)
to keep `meta` lean and avoid duplicating PII into the schedule table.

## What the command does per candidate

1. **`next_action_at = anchor_date + cycle_days`** — preserves the natural monthly stagger;
   nobody is dumped into "due now."
2. **Dead carry-over** — `dead=true` → row stored as `status='dead'` (recorded, never
   charged).
3. **Cutover interlock** — `already_billed_this_cycle=true` → push `next_action_at` to the
   *next* cycle, so going live never re-bills someone already billed this month.
4. **Backlog spread** — genuinely overdue rows are spread across `--spread-hours` (with
   jitter) so the first dispatch ramps instead of flooding the gateway.
5. **Idempotency** — `idempotency_key = {member}:{type}:{cycle}`, written with
   `insertOrIgnore`. Re-running inserts only what's missing.

## Dry run & the parity gate

```bash
php artisan billing:seed-schedule sports --dry-run
```

prints a metrics table:

| metric | meaning |
|---|---|
| `seen` | candidates yielded by the source |
| `pending` | rows that would be billable |
| `deferred` | pushed to next cycle (already billed this cycle) |
| `dead` | parked as dead |
| `inserted` / `duplicate` | (non-dry-run) new vs already-present |

**Before cutover**, compare `pending` against the population the legacy `rebillCC/PP/Cross`
query would select right now. They should match within an expected margin. Then run for
real, and **shadow one full cycle** — run the engine alongside the legacy command with the
engine's charges disabled or compared — before flipping the schedule.

## Re-running and corrections

- Safe to re-run any time; `insertOrIgnore` won't duplicate.
- To re-seed a corrected population for a future cycle, the next cycle's key differs
  (`...:{YYYYMM}`), so it inserts fresh rows without disturbing the current cycle.
- A botched seed for the *current* cycle is corrected by deleting the affected
  `pending` rows (never `done`) and re-running — do this only before cutover.

## One-shot enrolment: `settle` and `convert` (daily seeds)

Rebills are a **recurring** population — seed once, rows recur monthly. `settle` (the
`settleAuths` capture) and `convert` (the `convertInitials` conversion) are **one-shot,
per-auth** actions driven by *new* auths every day, so they're **enrolled daily** rather than
seeded once. Schedule them:

```php
// app Kernel — each on its own cadence
$schedule->command('billing:seed-schedule sports --type=settle')->hourly();
$schedule->command('billing:seed-schedule sports --type=convert')->daily();
```

Their candidates differ from rebills in two ways, which the source expresses with **two extra
fields** the command uses verbatim (falling back to cycle math when absent):

| Field | One-shot (settle/convert) | Recurring (rebill) |
|---|---|---|
| `next_action_at` | **explicit** = `auth_date + N days` (`settle_after_days` / `convert_days`) | omitted → `anchor + cycle_days` |
| `idempotency_key` | **explicit** = `{member}:{type}:{source_tr_id}` (per auth) | omitted → `{member}:{type}:{cycle}` |

The per-`source_tr_id` key is what makes a **daily** run safe: each auth enrols exactly one
row, and `insertOrIgnore` skips auths already enrolled, so re-running only adds newly-eligible
auths. `settle` carries the auth's MID (sticky capture); `convert` sets `mid_id = null` so the
handler's **rotation** resolver (mid-balancer trial pool) picks at charge time.

See `settleCaptures()` / `conversions()` in `examples/SeedSourceProvider.php`.

> Not yet modelled for these types: the `settleAuths` void check (`nmi_void`) and the
> conversion over-limit step-down (`105` → 19.90 after 10 days). Add the void check as a
> `settle` guard and the over-limit rung as a `convert` step-down rule before their cutover.

## Other types

`cross1`/`cross2` seed like rebills (recurring, from the cross UDF sets), by `billing_type`,
filtered by `--type`. Keep each type's "latest success / anchor" logic in the generator.
