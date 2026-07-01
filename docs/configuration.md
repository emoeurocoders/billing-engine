# Configuration

Every key in `config/billing-engine.php`. Publish with
`php artisan vendor:publish --tag=billing-engine-config`. Most per-vertical variance lives
here — see `examples/billing-engine.config.example.php` for two filled-in verticals.

## Top level

| Key | Default | Purpose |
|---|---|---|
| `vertical` | `env(BILLING_VERTICAL, 'sports')` | stack name; drives table names and the `stack` written to MID state |
| `cycle_days` | `30` | default billing cycle; overridable per type |
| `timezone` | `env(BILLING_TZ, 'MST7MDT')` | timezone for cycle/day calculations |

## `schedule`

The per-vertical source-of-truth table.

| Key | Default | Purpose |
|---|---|---|
| `connection` | `null` (default conn) | where the schedule table lives |
| `table` | `billing_schedule_{vertical}` | the table name |

## `mids`

The MID config source. Same-shape tables across verticals, all on `bigentertainment`.

| Key | Default | Examples |
|---|---|---|
| `connection` | `null` (= bigentertainment) | — |
| `table` | `mids` | `pdf_mids`, `manuals_mids` |

## `attempts`

The engine-owned unified attempt log (`billing_attempts_{vertical}`).

| Key | Default | Purpose |
|---|---|---|
| `connection` | `null` (default conn) | where the unified table lives |
| `table` | `billing_attempts_{vertical}` | the table name |

## `log`

Controls dual-writing and which source the "already attempted?" reads use.

| Key | Default | Purpose |
|---|---|---|
| `dual_write` | `true` | write the unified table **and** the legacy table |
| `legacy` | `true` | include the legacy per-type tables as a write target |
| `connection` | `omnistats` | where the legacy log tables live |
| `read_from` | `legacy` | `legacy` \| `unified` — read source for attempt checks |

Migration path: start with defaults (dual-write, read `legacy`). Once
`billing_attempts_{vertical}` has a full cycle of history, set `read_from=unified`; later
set `legacy=false` (stop writing legacy) and drop the legacy tables.

The legacy table **name** is resolved per attempt by an app-bound closure
`billing.logTable($ctx): string` (falls back to the type's static `log_table`). This is how
cross-product naming (`rebill_games_sports`, `rebill_{product}_{stack}`) is handled — see
`examples/AppServiceProviderWiring.php`.

## `negative_db`

The data source for the built-in `NegativeDb` guard (the do-not-bill gate). **These tables
differ per vertical/app, so the whole block is config** — override it in each vertical's
published config. The shipped defaults are the sports tables, so sports works out of the box.

| Key | Default | Purpose |
|---|---|---|
| `connection` | `omnistats` | connection the checks query |
| `tables.credits` | `auth_credits_sports` | credits/refunds table |
| `tables.chargebacks` | `auth_chargebacks_sports` | chargebacks table |
| `tables.cancels` | `cancels_sports` | cancelled members |
| `tables.bin_block` | `sports_bin_block` | blocked BINs |
| `tables.blocked` | `blocked_members` | blocked members |
| `tables.transactions` | `auth_transactions_sports` | ledger (hard-decline + decline-count checks) |
| `columns.*` | legacy names | member/bin/resp/udf/amount/date column names, if they differ per app |
| `hard_decline` | `106,107,111,112,123,164,165,201,264,460` | in-product permanent-stop codes |
| `hard_decline_stack` | `111,159` | stack-wide permanent-stop codes (any product) |
| `max_declines` | `3` | declines since last approval that stop the member |
| `product_udfs` | `cc/pp → UDF sets` | `card_type → tui_udf02 set` used to scope credit/hard-decline checks |
| `blacklisted_geo` | `[]` | countries blocked (conversions only) |

A hit returns **DEAD** (row never retried), in the same order as the legacy `negativeDb()`.
An app can bind `billing.negativeDb` to replace the built-in checks entirely. See
[guards.md](guards.md#negative_db--the-built-in-do-not-bill-gate). Note: the legacy
`rescueDecline()` FlexCharge side effect is intentionally **not** run in the guard (guards
are read-only; they also run under `--dry-run`).

## `gateway`

| Key | Default | Values |
|---|---|---|
| `driver` | `env(BILLING_GATEWAY, 'nmi')` | `nmi`, `inovio` |

The concrete gateway library instance is bound by the app as `billing.gatewayClient`; the
driver selects which adapter wraps it.

## `az`

The stored-card ("AZ") charge path (legacy `getAzKey()`). When a bound `CardVault` resolves a
card for a member, the handler charges it via `doCharge` instead of the token `doRebill`.

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | master switch; `false` → always token rebill (even if a vault is bound) |
| `udf_3` | `AZ` | tag sent on stored-card charges |
| `order_desc` | `null` | `setOrder` description; `null` → the uppercased vertical name |
| `tokens.connection` | `omnistats` | connection of the stored-card token table |
| `tokens.table` | `sports_tokens` | **the token table — different per vertical/app** |
| `tokens.columns.member` | `user_id` | token-row column holding the member id |

The vault itself is app-bound (`CardVault::class`); the package default is `NullCardVault`
(no stored cards). The token **table name is config, not a hardcoded model** —
`AbstractTokenCardVault` reads the token row from `az.tokens`, and the app subclass only
implements the decrypt. Set `az.tokens.table` per vertical. See
[gateways.md](gateways.md#the-az-stored-card-path). Card `exp` and `billing` come from the
vault or the seeded row `meta`.

## `queue`

| Key | Default | Purpose |
|---|---|---|
| `connection` | `null` | queue connection (start with `database`) |
| `name` | `billing` | queue name workers consume |
| `tries` | `3` | job attempts before `failed()` |
| `backoff` | `[60, 300, 900]` | seconds between retries |

## `dispatch`

| Key | Default | Purpose |
|---|---|---|
| `claim_batch` | `500` | rows claimed per dispatch tick |
| `lock_seconds` | `50` | intended dispatcher lock window (keep < schedule interval) |
| `per_mid_throttle` | `null` | reserved: per-MID pacing (e.g. `['max'=>1,'per_seconds'=>1]`) |

## `types`

The discriminator map. Each `billing_type` key configures its handler and behaviour.

```php
'types' => [
    'rebill' => [
        'handler'       => \Omni\BillingEngine\Handlers\RebillHandler::class,
        'selection'     => 'sticky',                 // sticky | rotation
        'udf2'          => 'CCR',                     // udf_2 sent on the charge
        'eligible_udf2' => ['CCC', 'CCR'],            // source filter (seeder/reporting)
        'cycle_days'    => 30,                        // overrides top-level
        'guards'        => ['already_attempted','mid_cap','negative_db','max_declines','same_day','conversion_rebill'],
        'log_table'     => 'rebill_sports',           // attempt log table (per vertical)
        'max_declines'  => 3,                         // used by max_declines guard
    ],
],
```

Per-type keys:

| Key | Used by | Notes |
|---|---|---|
| `handler` | registry | the handler class for this type |
| `selection` | `resolveMid` | `sticky` (rebill/cross/settle) or `rotation` (convert) |
| `udf2` | `buildPayload` | value sent as `udf_2` |
| `eligible_udf2` | seeder / reporting | which ledger UDF codes belong to this type |
| `cycle_days` | `scheduleNext` | per-type cycle override |
| `guards` | `GuardRunner` | ordered guard keys to run |
| `log_table` | `AttemptLogger` | legacy attempt-log table |
| `max_declines` | `MaxDeclines` guard | decline ceiling before `dead` |
| `stepdowns` | `StepDownPlanner` | decline-code-driven step-down ladder (see [step-downs.md](step-downs.md)) |
| `settle_after_days` | `settle` seeding | capture an auth N days after auth |

### `stepdowns` (per type)

```php
'stepdowns' => [
    'max_steps'        => 2,
    'on_exhausted'     => 'defer',                 // defer | dead
    'country_excluded' => ['JM','PG','RE','BS'],
    'rules' => [
        ['step'=>0,'card_type'=>'cc','codes'=>['104','105','109','157'],'after_days'=>8,'to'=>19.55,'mid'=>'sticky'],
        ['step'=>1,'card_type'=>'cc','codes'=>['105','109','157'],'after_days'=>3,'to'=>19.55,'mid'=>'match'],
    ],
],
```

Rule fields: `step` (rung; omit = any), `card_type` (cc|pp|any), `codes`, `after_days`,
`to` (null = keep amount), `mid` (sticky|match|new). Full reference: [step-downs.md](step-downs.md).

## `logging`

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | toggle engine logging |
| `channel` | `env(BILLING_LOG_CHANNEL, 'stack')` | Laravel log channel |

## Environment variables (summary)

```
BILLING_VERTICAL=sports
BILLING_GATEWAY=nmi
BILLING_MIDS_TABLE=mids
BILLING_MIDS_CONNECTION=
BILLING_SCHEDULE_TABLE=billing_schedule_sports
BILLING_SCHEDULE_CONNECTION=
BILLING_QUEUE_CONNECTION=database
BILLING_QUEUE=billing
BILLING_TZ=MST7MDT
BILLING_LOG_CHANNEL=stack
```
