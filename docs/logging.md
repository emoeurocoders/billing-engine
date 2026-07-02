# Logging & audit trail

Where every billing action is recorded, so you can answer "what happened to member X?" after
the fact. There are **two** independent records, plus the standard queue logs.

## 1. DB attempt log (authoritative, queryable)

Every **charge** that reaches the gateway — approved *or* declined — is written to the attempt
log by the `AttemptLogger` (dual-write during migration):

- `billing_attempts_{vertical}` (engine-owned, unified)
- the legacy per-type table (`rebill_sports`, …) alongside it

Columns include `member_id`, `billing_type`, `mid_id`, `amount`, `result`, `decline_code`,
`transaction_id`, `processor`, `cycle`, `date`. This is the source of truth for reconciliation
and reporting. See [data-model.md](data-model.md).

> Note: guard **skips/deads never reach the charge step**, so they are NOT in the attempt log.
> That's what the file audit trail (below) is for.

## 2. Per-action file audit trail (replaces legacy `log()`)

`BillingLogSubscriber` writes one line for **every action**, including the ones the DB log can't
see — the guard skips and deads. Registered automatically when `billing-engine.logging.enabled`
is true. Actions logged:

| Action | Level | Example line |
|---|---|---|
| `ATTEMPTING` | debug | `rebill cc member=30282522 amount=34.55 ATTEMPTING` |
| `SUCCESS` | info | `rebill cc member=30282522 amount=34.55 SUCCESS mid=ssyn… tr=9912837` |
| `DECLINE` | info | `rebill cc member=441 amount=34.55 DECLINE mid=sbmo… code=104` |
| `SKIP` | info | `rebill cc member=9181365 amount=19.55 SKIP reason=no_usable_mid` |
| `DEAD` | info | `rebill cc member=36663514 amount=34.55 DEAD reason=negative_db:credit` |
| `STEPDOWN` | info | `rebill cc member=77 amount=34.55 STEPDOWN to=19.55 after=8d mid=sticky` |

Each line also carries a **structured context array** (`member_id`, `type`, `card`, `amount`,
`cycle`, `schedule_id`, `vertical`, plus per-action fields like `decline_code`,
`raw_decline_code`, `transaction_id`, `reason`) — so a JSON channel gives you queryable fields.

### Setup — a dedicated rotating file

Define a channel in the app's `config/logging.php`:

```php
'channels' => [
    'billing' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/billing/billing.log'),
        'days'   => 90,
        'level'  => 'debug', // 'debug' includes ATTEMPTING; 'info' = outcomes only (one line/member)
    ],
],
```

Then point the engine at it:

```env
BILLING_LOG_CHANNEL=billing
```

`config('billing-engine.logging')`:

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | master switch for the file audit trail |
| `channel` | `null` | log channel name; `null` = the app's default channel |

Logging is wrapped so a broken channel can **never** throw into a billing run.

## 3. Dispatcher + queue logs

- **Dispatcher** — each `billing:dispatch` run logs `dispatch: claimed and dispatched N rows`
  (with the `claim_run` marker) to the same channel, and echoes to the console. For scheduled
  runs, also capture console output: `->appendOutputTo(storage_path('logs/billing/dispatch.log'))`.
- **Jobs** — a `ProcessBillingJob` that throws is retried per `queue.backoff`; a terminal failure
  lands in Laravel's `failed_jobs` table and `laravel.log`, and `failed()` releases the claim.

## Answering "what happened to member X?"

1. **Charged?** → query `billing_attempts_{vertical}` (or `rebill_sports`) for `member_id`.
2. **Not charged — why?** → grep the billing file log for `member=X` — the `SKIP`/`DEAD` line
   gives the exact reason (`no_usable_mid`, `negative_db:credit`, `same_day_transaction`, …).
3. **Whole run** → the dispatcher line gives the batch size + `claim_run` to correlate.
