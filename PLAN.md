# billing-engine — Design & Implementation Plan

> Standalone Laravel package for scheduled card-billing (rebills, cross-rebills,
> conversions, auth settlements) across all 6 verticals. Replaces the fragile,
> duplicated `NMIBilling` command loops that bill off the raw transaction ledger.

Status: **Design approved, ready to scaffold.** Sports is the pilot vertical.

---

## 1. Background & the problem

Each of the 6 verticals runs its own ~10k-line `NMIBilling` Artisan command. Billing
runs as Laravel **scheduled commands** (`Kernel.php`) that each loop over up to 4,500
rows pulled directly from the **transaction ledger** and charge them synchronously
with a `sleep()` between each.

Reference implementation analysed: `secure.sportzthrill.com/app/Console/Commands/NMIBillingDev.php`.

### Root causes of "rebills not processed / company loses money"

1. **`withoutOverlapping()` 24h lock.** A run that touches thousands of rows can take
   20–40 min; if it crashes/OOMs/hangs, the mutex isn't released and **every
   subsequent hourly run is silently skipped for up to 24h.** Primary culprit.
2. **No error isolation.** No `try/catch` around the charge; one gateway timeout or an
   unset `response_code` throws and **kills the rest of the batch**.
3. **`ensureDailyStats()` is called *inside* the loop** — reloads all active MIDs and
   upserts a daily-stats row for each, on every one of up to 4,500 iterations.
4. **The ledger is used as a fake queue.** `auth_transactions_{vertical}` (5.1M rows on
   sports) is mutated in place — `bat_date` as a cursor, `k1` as a dead-flag — and
   "due" is reconstructed every run from 30-day date math + per-row `rebill_*` probes
   against poorly-indexed columns.
5. **Cross-method races.** `rebillCC/PP/Settles/Cross1/Cross2` run on different minutes,
   all touching the same members/MIDs, with no shared lock.
6. **~1,000 lines of copy-paste** across 5 near-identical sticky-rebill methods; every
   fix must be made in 5 places × 6 verticals.
7. `sleep(0.5)` is a no-op (PHP `sleep()` truncates `0.5 → 0`).

---

## 2. Goals / non-goals

**Goals**
- A real, queue-driven billing pipeline: fast dispatcher + isolated, retryable jobs.
- A proper **per-vertical schedule table** as the source of truth — the ledger goes
  back to being read-only analytics.
- **One central, standalone package** installed in all 6 verticals; fix once, roll out.
- **Extensible** per vertical without forking the package.
- Kill the legacy `mids → sports_mids` cross-server sync; read MID config from the
  source of truth.

**Non-goals (for now)**
- Rewriting MID *selection* intelligence (mid-balancer stays as-is; we integrate via an
  adapter, see §6).
- Changing gateway/processor integrations (NMI etc.) — wrapped behind a contract.

---

## 3. Current architecture (the map)

**Three databases, three roles:**

| DB | Role | Key tables |
|---|---|---|
| `bigentertainment` (default conn) | **Config + runtime state engine** | `mids` (config registry: descriptor, redirect_mid, active, status, caps, `stack`), all **mid-balancer** tables (`rebills_daily_stats`, `mids_daily_stats`, …) |
| `omnistats` | **Legacy ledger + synced counters** | `auth_transactions_{v}` (ledger / fake queue), `sports_mids` (counters synced from `mids`), `rebill_{v}` (attempt log) |
| per-vertical app DBs | app data | — |

`UpdateMids.php` syncs `bigentertainment.mids → omnistats.sports_mids` on a schedule —
**dead weight** kept only because the two DBs used to live on separate servers. The
rebill loop reads MID config/counters from `sports_mids` instead of the source of truth.

### Method families (8 methods → 3 shapes)

| Family | Methods | Selection | Source | Charge |
|---|---|---|---|---|
| **Sticky rebill** | `rebillCC`, `rebillPP`, `rebillSettles`, `rebillCross1`, `rebillCross2` | sticky + `getRedirectMid()` | ledger + `bat_date`/`k1` cursor | `doRebill`/`doCharge` |
| **Scheduled cross** | `cross1`, `cross2` | rotation | `OmniCross` (**already a real queue**: `nextRebillDate`/`status`/`attempts`) | `doRebill`/`doCharge` |
| **Conversion / capture** | `convertInitials`, `convertPP`, `settleAuths` | rotation / sticky | `OmniAuth` / `OmniPrepaid` | `doCharge` / `doCapture` |

The sticky-rebill family is 5 methods that differ only in UDF filter, counter table, and
log table. `cross1/cross2` already prove the "real schedule table" pattern works in your
own codebase — the rebills are the ones doing it wrong.

---

## 4. Target architecture (overview)

```
                 ┌──────────────────────────────────────────┐
                 │  billing_schedule_{vertical}  (new table) │
                 │  source of truth: due dates, status, idem │
                 └──────────────────────────────────────────┘
                          ▲                       │ claim (atomic UPDATE)
       seed once          │                       ▼
  ┌───────────────┐   ┌───────────────┐   ┌────────────────────────────┐
  │ ledger /      │──▶│ billing:seed- │   │ billing:dispatch (1–2 min) │
  │ OmniCross/etc │   │ schedule (CLI)│   │ withoutOverlapping+expires │
  └───────────────┘   └───────────────┘   └────────────┬───────────────┘
                                                        │ dispatch 1 job / member
                                                        ▼
                                       ┌────────────────────────────────┐
                                       │ ProcessBillingJob (queued)     │
                                       │  guards → MidResolver → charge │
                                       │  → record → compute next due   │
                                       └──────────┬─────────────────────┘
                                                  │ (via contracts)
                          ┌───────────────────────┼───────────────────────┐
                          ▼                       ▼                        ▼
                   MidResolver             GatewayClient            AttemptLogger
              (app binds adapter)         (NMI wrapper)         (rebill_* / new log)
```

- **Dispatcher** never charges — it claims due rows and fans out jobs, then exits in
  seconds. Nothing long-held to wedge.
- **Job** processes one member with retries/backoff and per-MID throttling. One bad row
  fails one job, not the batch.
- **Throughput scales with workers**, not a single throttled loop.

---

## 5. Package design — standalone `omni/billing-engine`

- **No composer/code dependency on mid-balancer.** Integration is via the `MidResolver`
  contract (§6) the host app wires.
- Service provider publishes `config/billing-engine.php`, loads migrations, registers
  `billing:*` commands, and exposes a `BillingHandlerRegistry` + guard pipeline.
- Ships sensible defaults so a vertical with **no** mid-balancer still works (default
  `MidResolver` reads `mids` directly, default `AttemptLogger` writes a simple log table).

### Proposed package tree

```
billing-engine/
  composer.json                      # name: omni/billing-engine
  config/billing-engine.php          # per-type + per-vertical config
  database/migrations/
    ..._create_billing_schedule_table.php   # parameterised table name per vertical
    ..._create_billing_attempts_table.php   # unified attempt log (dual-written during migration)
  src/
    BillingEngineServiceProvider.php
    Contracts/
      MidResolver.php
      GatewayClient.php
      AttemptLogger.php
      BillingGuard.php
    Models/
      BillingSchedule.php            # table set at runtime from config/vertical
    Registry/
      BillingHandlerRegistry.php
    Handlers/
      BillingHandler.php             # abstract template method
      RebillHandler.php
      CrossHandler.php
      ConvertHandler.php
      SettleHandler.php
    Guards/
      AlreadyAttempted.php  SameDay.php  NegativeDb.php
      MaxDeclines.php  MidCap.php  ConversionRebill.php
    Pipeline/GuardRunner.php
    StepDown/StepDownPlanner.php             # decline-code-driven step-down ladders
    Console/Commands/
      SeedScheduleCommand.php        # billing:seed-schedule {vertical} --dry-run
      DispatchCommand.php            # billing:dispatch
    Jobs/ProcessBillingJob.php
    Events/
      BillingAttempting.php BillingSucceeded.php
      BillingDeclined.php  BillingSkipped.php  BillingDead.php
    Gateways/
      NmiGateway.php  InovioGateway.php        # GatewayClient adapters
    Resolvers/
      DirectMidResolver.php                    # default: reads configured mids table
    Support/MidDecision.php  GatewayResult.php  GuardResult.php  BillingContext.php
  examples/                                    # copy-paste starting points per vertical
    README.md
    AppServiceProviderWiring.php               # bind MidResolver + GatewayClient + handlers
    KernelSchedule.php                         # how to schedule billing:dispatch
    SportsMidBalancerAdapter.php               # MidResolver delegating to mid-balancer
    CustomCrossHandler.php                     # subclass a handler hook (stepdown ladder)
    CustomGuard.php                            # add a vertical-specific guard
    billing-engine.config.example.php          # a filled-in per-vertical config
  tests/
```

---

## 6. Contracts & the mid-balancer adapter (how "no dependency" works)

billing-engine defines small interfaces it owns; the **app** binds implementations.

```php
interface MidResolver {
    public function resolveStickyMid(string $midId, string $stack, array $ctx): ?MidDecision;
    public function resolveRotationMid(string $stack, array $ctx): ?MidDecision;
    public function recordResult(MidDecision $mid, bool $approved, array $details = []): void;
}

interface GatewayClient {
    public function rebill(array $payload): GatewayResult;   // doRebill
    public function charge(array $payload): GatewayResult;   // doCharge
    public function capture(string $ref): GatewayResult;     // doCapture (settles)
}
```

**Two gateways, one contract (confirmed against prod).** 3 verticals bill via **NMI**, 3 via
**Inovio** (`secure.eszpdf.com/app/Library/Inovio.php`, `InovioBilling.php`). Both already
expose the *same method names* — `doCharge` / `doRebill` / `doCapture` — return `1`/`0`, and
populate `$gateway->response` with `response_code` / `responsetext` / `transactionid`. They
differ only in:
- **payload keys** (NMI `uid`/`trans_id` vs Inovio `cust_id`), handled by the per-vertical
  `buildPayload()` hook emitting a *canonical* payload that each adapter translates;
- **decline-code map** (each gateway's codes → canonical), per-gateway config;
- **counter table** (e.g. Inovio/pdf writes `pdf_mids`), handled by the MID adapter.

The package ships `NmiGateway` and `InovioGateway` adapters; the app binds whichever via the
`GatewayClient` contract. `GatewayResult` normalises `{approved, responseCode, responseText,
transactionId, raw}` so handlers never see gateway-specific shapes.
```php
final class GatewayResult {
    public function __construct(
        public bool $approved, public ?string $responseCode, public ?string $responseText,
        public ?string $transactionId, public array $raw = [],
    ) {}

interface AttemptLogger {
    public function alreadyAttempted(string $memberId, string $type, string $cycle): ?Attempt;
    public function record(array $attempt): void;
}
```

- **Default adapters** ship in the package: `DirectMidResolver` (reads the MID config
  table + `redirect_mid` failover), and a `DualAttemptLogger` that writes the engine-owned
  unified `billing_attempts_{vertical}` table **and** the legacy per-type tables during
  migration (reads from `log.read_from`; flip to unified once it has history, then drop the
  legacy tables). Legacy table names — including runtime cross-product naming — resolve via
  an app-bound `billing.logTable($ctx)` closure since all those tables share one schema.
- **The MID source table is configurable per vertical.** Not all verticals read from the
  same table — e.g. `mids`, `pdf_mids`, `manuals_mids`. `DirectMidResolver` (and the
  sports `MidBalancerAdapter`) take the table/connection from `billing-engine.mids.*`
  config, so swapping the source is a config change, never a code change.
- **Sports app** binds `MidBalancerAdapter implements MidResolver` that delegates to the
  existing `MidBalancer` facade (sticky validation against `rebills_daily_stats`,
  `recordRebillResult`, descriptor matching for redirect). billing-engine never
  references mid-balancer; the app glues them. Any vertical without mid-balancer falls
  back to the default resolver.

---

## 7. Data model — `billing_schedule_{vertical}`

One table **per vertical** (millions of rows × 6; follows the existing `_{vertical}`
suffix convention and keeps each working set hot). Table name resolved from config.

```sql
CREATE TABLE billing_schedule_sports (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  member_id       VARCHAR(64)  NOT NULL,
  billing_type    ENUM('rebill','cross1','cross2','convert','settle') NOT NULL,
  card_type       ENUM('cc','pp') NOT NULL,
  source_tr_id    VARCHAR(64),                 -- originating ledger tx
  mid_id          VARCHAR(64),                 -- sticky MID (nullable for rotation types)
  amount          DECIMAL(10,2) NOT NULL,
  next_action_at  DATETIME     NOT NULL,       -- explicit due date (no more date-window math)
  status          ENUM('pending','claimed','done','dead','skipped') NOT NULL DEFAULT 'pending',
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  claimed_at      DATETIME NULL,
  last_decline_code VARCHAR(16) NULL,
  cycle           CHAR(6) NOT NULL,            -- YYYYMM billing cycle
  idempotency_key VARCHAR(128) NOT NULL,       -- {member_id}:{billing_type}:{cycle}
  meta            JSON NULL,                   -- per-vertical extras (descriptor, udfs…)
  created_at      DATETIME, updated_at DATETIME,

  UNIQUE KEY uq_idem (idempotency_key),                 -- double-bill impossible
  KEY idx_due (status, next_action_at),                 -- dispatcher claim
  KEY idx_member (member_id, billing_type)
);
```

- `billing_type` discriminator unifies the sticky-rebill family **and** absorbs
  cross1/cross2/convert/settle. **Settles** keep their own due semantics (capture 2 days
  after auth) but live in the same table/machinery.
- `UNIQUE(idempotency_key)` makes double-charging structurally impossible, replacing the
  race-prone `rebill_*` probes.
- `next_action_at` is the only "is it due?" signal — no recomputation, no `DATE()` scans.

---

## 8. Extensibility model — by *kind* of variance

The key design principle: **match each kind of difference to the lightest mechanism**.
A vertical reaches for heavier machinery only when the lighter layer can't express it.

| Layer | Handles | Mechanism | Override cost |
|---|---|---|---|
| **Config** | amounts, UDF filters, cycle days, **MID source table/connection** (`mids` / `pdf_mids` / `manuals_mids`), MID pool, decline-code map, stepdown ladder, log table | `config/billing-engine.php` per `billing_type` | edit array |
| **Handlers** | flow/behaviour differences | Template-method `BillingHandler` hooks; registry per `billing_type` | subclass + 1 bind line |
| **Guards** | rule differences | composable guard pipeline, classes returning pass/skip/dead | config array entry |
| **Events** | side-effects (receipts, cross-sell, telemetry) | Laravel events + listeners | add a listener |
| **Contracts** | infra swaps (MID source, gateway, log) | interfaces, app-bound | bind an impl |

### 8a. Handler template method

```php
abstract class BillingHandler {
    final public function handle(BillingSchedule $row): void {
        $ctx = $this->context($row);
        if ($verdict = $this->guards()->run($ctx)) {        // skip|dead → record & return
            return $this->finish($row, $verdict);
        }
        $mid    = $this->resolveMid($ctx);                   // override: sticky vs rotation
        $payload= $this->buildPayload($ctx, $mid);           // override: descriptor/udfs/amount
        $result = $this->charge($payload);                   // override: rebill/charge/capture
        $result->approved
            ? $this->onApproved($row, $mid, $result)
            : $this->onDeclined($row, $mid, $result);        // override: stepdown ladder
        $this->scheduleNext($row, $result);                  // override: cycle/stepdown timing
    }
    // overridable hooks: context, guards, resolveMid, buildPayload, charge,
    // onApproved, onDeclined, scheduleNext
}
```

Package ships `RebillHandler`, `CrossHandler`, `ConvertHandler`, `SettleHandler`.
A vertical customises by subclassing and rebinding:

```php
// in a vertical's AppServiceProvider
BillingHandlerRegistry::bind('cross2', SportsFitCrossHandler::class);
// class SportsFitCrossHandler extends CrossHandler {
//     protected function scheduleNext(...) { /* 19.87 → 9.87 ladder */ }
// }
```

### 8b. Guard pipeline

```php
interface BillingGuard { public function check(BillingContext $ctx): GuardResult; }
// GuardResult::pass() | ::skip($reason) | ::dead($reason)
```

Default chain (config-ordered): `AlreadyAttempted`, `SameDay`, `NegativeDb`,
`MaxDeclines`, `MidCap`, `ConversionRebill`. A vertical appends/removes via config or
registers a custom guard class — no handler edits.

### 8c. Events for side-effects

`BillingAttempting`, `BillingSucceeded`, `BillingDeclined`, `BillingSkipped`,
`BillingDead`. Verticals attach listeners for receipts, cross-sell creation (`addCross`),
analytics — keeping side-effects out of the core charge path.

---

## 9. Backfill seeder — `billing:seed-schedule {vertical} --dry-run`

Materialises the legacy "reconstructed-every-run" rebill set into the new table **once**,
without double-billing anyone mid-cycle. Re-runnable (idempotent via unique key), chunked.

Per billing line:
1. **One row per member per `billing_type`, from their latest success.** Group ledger
   `resp_id=0` rows by `cust_id_ext`, take the most recent → establishes sticky MID,
   amount, card type, anchor date.
2. **`next_action_at = latest_success.tr_date + cycle (30d)`** — preserves natural stagger;
   nobody is dumped into "due now".
3. **Reconcile against `rebill_{v}`** — members already attempted in the current cycle
   window get pushed to the *next* cycle (cutover safety interlock).
4. **Carry over dead/skip markers** (`k1=2`, max-declines, negative-DB, cancellations) as
   `status='dead'/'skipped'` — recorded, never charged.
5. **`idempotency_key = {member_id}:{billing_type}:{cycle_YYYYMM}`**, `insertOrIgnore`.
6. **Backlog spread**: rows already overdue are spread across a configurable window so the
   queue ramps instead of flooding the gateway.

**Parity gate (before cutover):** the command prints counts + a member-set diff against
what `rebillCC/PP/Cross` would select right now. Confirm the population matches, run in
shadow for one cycle, compare attempts, then retire the legacy loops.

Same seeder backfills crosses (`OmniCross`), conversions (`OmniAuth`/`OmniPrepaid`),
settles (`OmniAuth`) by `billing_type`.

---

## 10. MID-source migration (retire omnistats for billing)

1. Read MID **config** from the configured source table (via `MidResolver`) — default
   `bigentertainment.mids`, overridable per vertical to `pdf_mids`, `manuals_mids`, etc.:
   ```php
   // config/billing-engine.php
   'mids' => [
       'connection' => env('BILLING_MIDS_CONNECTION', 'mysql'), // default = bigentertainment
       'table'      => env('BILLING_MIDS_TABLE', 'mids'),       // pdf: 'pdf_mids', manuals: 'manuals_mids'
   ],
   ```
   **Confirmed against prod:** `mids` (2,235 rows), `pdf_mids` (913), `manuals_mids` (175)
   all live on `bigentertainment` and are **the same shape** — they share every column the
   sticky-rebill path needs (`mid_id, redirect_mid, descriptor, active, status, cross1,
   cross2, daily_caps, cc_cards, pp_cards, rebills_only`). `mids` carries a few extras
   (`mcc, conv_cap, priority, overflow, daily_order, mid_address`) the rebill path doesn't
   touch. So `DirectMidResolver` needs only a configurable table name (null-coalescing the
   handful of optional columns) — **no per-table column map**. Each table also has an
   omnistats counter-twin (`*_mids` synced by `UpdateMids`) which we retire (§10).
2. Read/write MID **runtime state** from `rebills_daily_stats` (mid-balancer adapter) —
   already being written today.
3. Once nothing reads `sports_mids`, **delete the `UpdateMids` → `sports_mids` sync** and
   the `sports_mids` reads/counter-updates.

---

## 11. Queue backend

- Today `QUEUE_CONNECTION=sync` (jobs would run inline — no benefit). Redis is a
  **cluster** (`clustercfg…elasticache`).
- **Recommended start: database queue** — transactional with the schedule table, no
  Redis-cluster slot/hash-tag gotchas, fine at ~4,500/hr/vertical. Upgrade to
  Redis+Horizon later if throughput demands it (needs hash-tagged keys or a non-clustered
  instance for queues).
- Jobs written backend-agnostic so the connection is a deploy-time flip.

---

## 12. Phased rollout

> **No changes to the existing `NMIBilling`/`InovioBilling` code.** We build the new engine
> alongside it and migrate **one vertical at a time** once each is verified. The legacy
> command keeps running untouched for every not-yet-migrated vertical.

| Phase | Scope | Outcome |
|---|---|---|
| **1. Package skeleton** | `omni/billing-engine`, service provider, config, contracts + default adapters + **examples/** | installable, no prod wiring |
| **2. Schedule + sticky-rebill** | `billing_schedule_{v}`, `RebillHandler`, guards, dispatcher, job; `NmiGateway`/`InovioGateway` | unifies `rebillCC/PP/Settles/Cross1/Cross2` |
| **3. Backfill + shadow (sports)** | `billing:seed-schedule sports --dry-run` → parity gate → shadow one cycle | verified population |
| **4. Cutover sports** | schedule `billing:dispatch` for sports; stop legacy sports loops | sports live on new engine |
| **5. Conversions/settles** | `ConvertHandler`/`SettleHandler` as billing types | full coverage |
| **6. Roll vertical-by-vertical** | per vertical: publish config (gateway + mids table), seed, shadow, cutover | NMI ×3 + Inovio ×3 migrated independently |

Each vertical migrates on its own timeline; retiring `*_mids`/`UpdateMids` happens per
vertical only after its cutover is verified.

---

## 13. Open decisions / risks

- **Cycle length / amounts per vertical** — confirm the 30-day cycle and amount/stepdown
  ladders are config-expressible for all 6 (sports cross1 19.90→9.90, cross2 fit
  19.87→9.87 already differ).
- **AZ-token path** (`getAzKey`/Vault) — wrap behind `GatewayClient`/payload builder so it
  stays per-vertical.
- **Settle semantics** — capture-after-2-days lifecycle modelled as a `billing_type` with
  its own `next_action_at` rule; confirm no settle needs a separate table.
- **Queue backend** — start `database`; revisit Redis/Horizon after sports is live.
- **Shadow-mode parity** — must match the legacy population before cutover; budget time
  for one full cycle of comparison.

---

## 14. Immediate next steps

1. Scaffold `omni/billing-engine` (composer, service provider, config, contracts).
2. Write `billing_schedule` migration (parameterised table name).
3. Implement `RebillHandler` + guard pipeline + `ProcessBillingJob` + `billing:dispatch`.
4. Implement `billing:seed-schedule` with `--dry-run` + parity output.
5. In parallel: ship the Phase-0 hotfix to sports `NMIBillingDev.php`.
