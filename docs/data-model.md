# Data model

## `billing_schedule_{vertical}`

One table per vertical (millions of rows × 6 verticals). The name and connection are
resolved from config at runtime, so the same migration and `BillingSchedule` model serve
every vertical.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | — |
| `member_id` | varchar(64) | the customer this row bills |
| `billing_type` | enum | `rebill` / `cross1` / `cross2` / `convert` / `settle` — selects the handler + config block |
| `card_type` | enum | `cc` / `pp` |
| `source_tr_id` | varchar(64) | originating ledger transaction (rebill token / capture ref) |
| `mid_id` | varchar(64) | sticky MID; null for rotation types |
| `amount` | decimal(10,2) | charge amount (mutated by step-down on decline) |
| `next_action_at` | datetime | **the only "is it due?" signal** |
| `status` | enum | `pending` / `claimed` / `done` / `dead` / `skipped` |
| `attempts` | smallint | attempts made this cycle |
| `step` | smallint | step-down rung (0 = full price); see [step-downs.md](step-downs.md) |
| `claimed_at` | datetime | when a dispatch run claimed it |
| `last_decline_code` | varchar(16) | canonical decline code of the last attempt |
| `cycle` | char(6) | `YYYYMM` billing cycle |
| `idempotency_key` | varchar(128) | `{member_id}:{billing_type}:{cycle}` — UNIQUE |
| `meta` | json | per-vertical extras (udf_1, bin, descriptor overrides, claim marker) |
| `created_at` / `updated_at` | datetime | — |

### Indexes

| Index | Columns | Used by |
|---|---|---|
| `uq_idem` (unique) | `idempotency_key` | seeder `insertOrIgnore`; prevents double-billing |
| `idx_due` | `status, next_action_at` | dispatcher claim query |
| `idx_member` | `member_id, billing_type` | lookups / reporting |

The model sets its connection/table in the constructor:

```php
$this->setConnection(config('billing-engine.schedule.connection'));
$this->setTable(config('billing-engine.schedule.table', 'billing_schedule_sports'));
```

## Status lifecycle

```
            ┌────────── skip: defer to next cycle ─────────┐
            ▼                                               │
  pending ──claim──▶ claimed ──charge──▶ approved ──▶ done  │
            │            │                  │               │
            │            │ decline          └─ declined ──▶ pending (retry, attempts++)
            │            ▼
            │      job failure ──▶ pending (claim released by Job::failed)
            ▼
  guard DEAD (hard decline / max declines / negative-db / cancelled) ──▶ dead
```

- **pending** → eligible to claim once `next_action_at <= now`.
- **claimed** → exactly one job owns it. The job only acts if it still sees `claimed`
  (`ProcessBillingJob::handle` guards on this), so re-dispatch or duplicate claims are safe.
- **done** → billed this cycle; `next_action_at` advanced by `cycle_days`.
- **dead** → terminal; never retried. Set by a `DEAD` guard verdict or the cross max-attempt
  cancel.
- **skipped** → not its own stored status in most paths; transient deferrals reset the row to
  `pending` with `next_action_at` pushed forward and emit a `BillingSkipped` event. (The
  `skipped` enum value exists for seeded rows that should be parked without charging.)

## Idempotency

The cycle key `{member_id}:{billing_type}:{cycle}` is the backbone of correctness:

- The **seeder** writes rows with `insertOrIgnore`, so re-running it never duplicates and is
  safe after a gap.
- A member can have **at most one row per type per cycle**, so two overlapping dispatch runs
  or a re-seed cannot produce two charges.
- This replaces the legacy approach of probing the `rebill_*` log table per row to guess
  whether someone was already billed — which was race-prone and depended on un-indexed
  `DATE()` predicates.

## `next_action_at` — the due date

The legacy code had **no** explicit due date; it recomputed a 30-day window each run. Here
`next_action_at` is set once and advanced deterministically:

- **on approval** → `now + cycle_days` (next cycle)
- **on decline** → `now + 1 day` (retry tomorrow), until max declines → `dead`
- **on skip** → `now + cycle_days` (try again next cycle)
- **at seed time** → `latest_success.tr_date + cycle_days`, preserving the natural stagger;
  overdue backlog is spread across `--spread-hours` (see [backfill.md](backfill.md)).

## `billing_attempts_{vertical}` — the unified attempt log

The engine owns a second table: a **unified attempt log** holding every `billing_type` in
one place, replacing the ~50 legacy `rebill_*` / `conversion_*` / `settle_*` tables.

| Column | Notes |
|---|---|
| `member_id` | legacy `uid` |
| `billing_type` | rebill / cross1 / cross2 / convert / settle |
| `cross_target` | the cross product (games/vod/fit/…) when applicable |
| `card_type`, `mid_id`, `amount` | — |
| `result` | 1 = approved |
| `decline_code`, `transaction_id`, `processor` | legacy `decline_code` / `tr_id` / `processor` |
| `attempt_no`, `retry` | legacy `attempt` / `retry` (kept for parity) |
| `schedule_id` | links back to the `billing_schedule` row |
| `cycle`, `date` | `YYYYMM` + attempt timestamp |

Indexes: `(member_id, billing_type, date)` for the "already attempted this cycle?" lookup,
`(result, billing_type)` and `(date)` for reporting.

### Dual-write during migration

The legacy per-type tables all share **one schema** (only the table *name* varies, and for
crosses it can depend on the member's cross product at runtime). So during migration the
engine **writes both**:

- **unified** `billing_attempts_{vertical}` (always), and
- **legacy** table, name resolved by an app-bound `billing.logTable($ctx)` closure (or the
  static per-type `log_table` config) — so existing dashboards and the still-running
  step-down/auth-retry crons keep working.

Reads (the "already attempted?" checks) come from a single configured source
(`log.read_from`): keep it on **`legacy`** until the unified table has a full cycle of
history, then flip to **`unified`** and retire the legacy write + tables. This is wired by
`DualAttemptLogger` — see [configuration.md](configuration.md#log) and
[guards.md](guards.md).

## Relationship to existing tables

The engine **reads** `mids`/`pdf_mids`/`manuals_mids` (config) and, during migration,
**dual-writes** the legacy `rebill_*` attempt logs alongside its own
`billing_attempts_{vertical}`. It does **not** mutate the `auth_transactions_*` ledger at
runtime — the ledger is read only once, at seed time.
