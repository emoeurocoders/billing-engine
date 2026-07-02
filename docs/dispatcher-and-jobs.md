# Dispatcher & jobs

Billing is split into a **dispatcher** (claims due work, fans out jobs, exits fast) and a
**job** (bills one member, isolated and retryable). This is the structural fix for the
legacy "one long synchronous loop" failure modes.

## `billing:dispatch`

```
billing:dispatch [--type=rebill --type=cross1 ...] [--limit=500]
```

Scheduled every 1–2 minutes. Each run:

1. **Atomically claims** a batch of due rows in a single `UPDATE`:

   ```sql
   UPDATE billing_schedule_{v}
      SET status='claimed', claimed_at=:now, meta=JSON_SET(meta,'$.claim_run', :runId)
    WHERE status='pending' AND next_action_at <= :now
      [AND billing_type IN (:types)]
    ORDER BY next_action_at
    LIMIT :batch;
   ```

   The single-statement flip from `pending`→`claimed` is the lock: two concurrent dispatch
   runs cannot claim the same row.

2. **Fans out** one `ProcessBillingJob` per freshly-claimed row (matched by `claimed_at` +
   the run marker), then **exits in seconds**.

Tuning (`config('billing-engine.dispatch')`):

| Key | Meaning |
|---|---|
| `claim_batch` | rows claimed per tick (default 500) — overridable with `--limit` |
| `lock_seconds` | intended dispatcher lock window (keep < the schedule interval) |
| `per_mid_throttle` | reserved for per-MID pacing config |

Schedule it with a **short** `withoutOverlapping`, the opposite of the legacy 24h default:

```php
$schedule->command('billing:dispatch')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();
```

### Canary run — test a few members before the full cutover

Before letting the scheduler loose on the whole due population, run one **manual, tiny batch**
and inspect the outcome (attempt log + billing file log) for those members:

```bash
# Option A — the first 5 due rows
php artisan billing:dispatch --type=rebill --limit=5

# Option B — specific members you KNOW have a live MID (pick them from the dry-run CSV;
# --limit alone grabs the earliest-due rows, which may all SKIP on no_usable_mid)
php artisan billing:dispatch --type=rebill --member=30282522 --member=441
```

Both flags apply to `--dry-run` too, so you can preview the exact same canary set first:

```bash
php artisan billing:dispatch --type=rebill --member=30282522 --dry-run
```

Because the claim is atomic and per-row, a canary run charges only the matched rows and leaves
every other `pending` row untouched — safe to run against live data while the scheduler is off.
`--member` accepts the flag repeatedly (`--member=A --member=B`) or a single id.

## `billing:dispatch --dry-run` — preview before charging

```
billing:dispatch --dry-run [--type=rebill ...] [--limit=N] [--out=preview.csv]
```

A **read-only** preview of the next dispatch. It selects due rows with the *same* predicate
as the real claim (`status='pending' AND next_action_at <= now`, optional `--type` filter),
then runs each one through the **real guard pipeline and MID resolution** — and stops one
step before the gateway charge. It is the answer to "who will this run actually bill, and
for how much?" *before* a single card is touched.

What it does **not** do, by construction:

- it does **not** claim (rows stay `pending`),
- it does **not** dispatch `ProcessBillingJob`s,
- it does **not** call the gateway, and
- it does **not** write the schedule row, MID state, or the attempt log.

This is safe because every guard (`already_attempted`, `same_day`, `negative_db`,
`max_declines`, `mid_cap`, `conversion_rebill`) and the MID resolver are **read-only
checks** — the decision the preview reaches is the decision the worker would reach moments
later. The logic lives in `Preview\BillingPreviewer`, which deliberately mirrors
`BillingHandler::handle()` up to the charge.

Per due row it reports one disposition:

| Disposition | Meaning | Charged? |
|---|---|---|
| `CHARGE` | guards passed and a MID resolved — this member would be billed `amount` on `mid` | the only one that would bill |
| `SKIP` | a guard returned SKIP (already attempted-declined, same-day, …) → deferred next cycle | no |
| `DEAD` | a guard returned DEAD (already approved this cycle, max declines, …) → parked | no |
| `NO_MID` | guards passed but no usable MID resolved → deferred | no |

Output is a per-member sample table (first 25), a summary with the **WOULD CHARGE count and
dollar total**, and a breakdown of skip/dead reasons. `--out=path.csv` writes the *full*
per-member list (`member_id, billing_type, card_type, amount, step, disposition, mid_id,
reason`) so you can diff it against the legacy population before cutover.

Without `--limit` the preview covers the **whole due population** (not the 500-row
`claim_batch` cap) — the point of a pre-cutover check is to see everyone. Note it issues a
MID-resolution query per row, so a large preview is heavier than a real dispatch tick; run
it ad hoc, not on a schedule.

## `ProcessBillingJob`

One member per job. Key properties:

- **Isolation** — a throw fails only this job; the rest of the batch is unaffected.
- **Idempotent claim check** — `handle()` only proceeds if the row is still `claimed`. A
  re-dispatched or duplicated job is a no-op.
- **Retries/backoff** — `tries` and `backoff` from config (`queue.tries`,
  `queue.backoff = [60,300,900]`).
- **Per-MID throttle** — a `WithoutOverlapping("billing-mid:{midId}")` middleware serialises
  charges on the same MID, replacing the global `sleep()` with targeted pacing.
- **Failure handling** — `failed()` releases the claim (`claimed` → `pending`, clears
  `claimed_at`) so the dispatcher re-picks it next cycle.

```php
public function handle(BillingHandlerRegistry $registry): void
{
    $row = BillingSchedule::find($this->scheduleId);
    if (!$row || $row->status !== BillingSchedule::STATUS_CLAIMED) return; // already handled

    $ctx = new BillingContext($row, config('billing-engine.vertical'),
                              $row->billing_type, config("billing-engine.types.{$row->billing_type}"));
    $registry->resolve($row->billing_type)->handle($ctx);
}
```

## Queue backend

```php
'queue' => [
    'connection' => env('BILLING_QUEUE_CONNECTION', null),
    'name'       => env('BILLING_QUEUE', 'billing'),
    'tries'      => 3,
    'backoff'    => [60, 300, 900],
],
```

**Start with `database`** — transactional with the schedule table, no Redis-cluster
slot/hash-tag gotchas, and fine at ~4,500 rows/hour/vertical. Workers:

```bash
php artisan queue:work database --queue=billing --tries=3 --backoff=60,300,900
```

Move to **Redis + Horizon** later if throughput demands it; the Redis here is cluster-mode
(`clustercfg…elasticache`), so queue keys need hash-tagging or a non-clustered instance.
Because the job reads connection/queue from config, the switch is a config change.

## Throughput model

- Legacy: one process, up to 4,500 rows × (charge + `sleep`) serially → tens of minutes,
  single point of failure.
- Engine: N workers process jobs in parallel; wall-clock scales with worker count and
  per-MID throttle, and a slow/failed charge affects only its own job.

## Failure & recovery scenarios

| Scenario | Behaviour |
|---|---|
| Gateway timeout on one member | that job retries with backoff; others proceed |
| Worker dies mid-job | job returns to the queue (standard Laravel); claim released on final failure |
| Dispatcher run crashes after claiming | claimed rows are re-picked next tick once jobs run/fail; no 24h wedge |
| Duplicate dispatch | second claim sees no matching `pending` rows; jobs no-op on non-`claimed` rows |
