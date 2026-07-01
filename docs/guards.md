# Guards

A **guard** is a single pre-charge rule. Guards run as an ordered pipeline before any MID
is resolved or any charge is made. The pipeline is **config-driven** per billing type, so a
vertical reshapes its rules without touching handler code.

## The contract

```php
interface BillingGuard
{
    public function key(): string;                 // matches the config `guards` array
    public function check(BillingContext $ctx): GuardResult;
}
```

`GuardResult` has three outcomes:

| Outcome | Meaning | Row effect |
|---|---|---|
| `pass()` | rule satisfied, continue | none |
| `skip($reason)` | transiently not eligible | deferred to next cycle, `BillingSkipped` |
| `dead($reason)` | permanently not eligible | `status = dead`, `BillingDead` |

The first non-`pass` result short-circuits the pipeline (`GuardRunner::run`).

## Configuring the chain

Each type lists the guard keys it runs, in order:

```php
'types' => [
    'rebill' => [
        'guards' => ['already_attempted', 'mid_cap', 'negative_db', 'max_declines', 'same_day', 'conversion_rebill'],
        // ...
    ],
],
```

Order matters — put cheap/decisive guards first. Remove a key to drop a rule; add a key
(after registering the guard) to add one.

## Built-in guards

| Key | Class | Outcome | Backed by |
|---|---|---|---|
| `already_attempted` | `AlreadyAttempted` | DEAD if approved this cycle, SKIP if declined | `AttemptLogger` |
| `same_day` | `SameDay` | SKIP if an approved tx exists today | `billing.sameDayCheck` closure |
| `negative_db` | `NegativeDb` | DEAD with reason | **built-in, config-driven** (`billing-engine.negative_db`) |
| `max_declines` | `MaxDeclines` | DEAD at the decline ceiling | `billing.declineCount` closure |
| `mid_cap` | `MidCap` | SKIP if no usable sticky MID | `MidResolver` |
| `conversion_rebill` | `ConversionRebill` | SKIP if already approved in the ledger this cycle | **built-in, config-driven** (`billing-engine.conversion_rebill`) |

### `negative_db` — the built-in do-not-bill gate

`NegativeDb` is a full, faithful port of the legacy `NMIBilling::negativeDb()` — it runs the
do-not-bill checks itself, in the same order, and returns **DEAD** on the first hit (the
row is never retried, matching the legacy `k1=2`):

1. `credit` — a non-VOID credit/refund (amount ≠ 2) on this product
2. `chargeback` — the member has a chargeback
3. `cancel` — the member is in the cancels table
4. `bin_block` — the member's card BIN is blocked
5. `block` — the member is in the blocked-members table
6. `hard_decline` (in-product) — a prior hard-decline code on this product
7. `hard_decline` (stack) — a stack-wide hard-decline code on any product
8. `blacklisted_geo` — conversions only, country on the block list
9. `max_declines` — declines since the last approval ≥ the ceiling

**Everything is config, because these tables differ per vertical/app.** The connection,
table names, column names, decline-code lists, `max_declines`, the `card_type → product
UDF` map and the blacklisted-geo list all come from the `billing-engine.negative_db` block
— see [configuration.md](configuration.md#negative_db). Override that block in each
vertical's published config; nothing needs a code change. The built-in defaults are the
sports tables (`auth_credits_sports`, `sports_bin_block`, …) so sports works out of the box.

> **Deliberate deviation:** the legacy `negativeDb()` also fired `rescueDecline()` — a real
> FlexCharge charge — as a side effect when a member was over the decline ceiling. That is
> **not** done inside the guard: guards must be read-only, because they also run under
> `billing:dispatch --dry-run`, where issuing a charge would bill people during a preview.
> Rescue-decline should be wired as a post-decline step/listener instead.

An app can still bind `billing.negativeDb` to **replace** the built-in checks entirely; when
bound it takes over and receives the `BillingContext`:

```php
$this->app->instance('billing.negativeDb', fn (BillingContext $ctx): ?string => /* reason|null */);
```

### `conversion_rebill` — the built-in "already billed this cycle" gate

`ConversionRebill` ports the legacy `conversionRebill()`: it **SKIPs** a member who already
has an **approved ledger transaction** (`resp_id=0`) in this product's UDF set within the
current cycle window. It reads the **ledger**, unlike `already_attempted` (which reads the
`rebill_*` attempt log) — so it catches members already billed this cycle through a different
path that never wrote a rebill-log row.

Config-driven via `billing-engine.conversion_rebill` (`connection`, `table`, `columns`,
`product_udfs`, `cycle_days`) — sports tables are the defaults; see
[configuration.md](configuration.md#conversion_rebill). Bind `billing.conversionRebill` to
replace the built-in check entirely (closure receives the `BillingContext`, returns `bool`).

### Why closures for the other data-driven guards

`SameDay` and `MaxDeclines` still need vertical-specific SQL against tables the package
shouldn't hardcode. Each resolves an **app-bound closure** if present, and passes through
(`pass()`) if not:

```php
$this->app->instance('billing.sameDayCheck', fn (string $memberId): bool => /* ... */);
$this->app->instance('billing.declineCount', fn (string $memberId, string $type): int => /* ... */);
```

## Writing a custom guard

```php
class ChaseBinGuard implements BillingGuard
{
    public function key(): string { return 'chase_bin'; }

    public function check(BillingContext $ctx): GuardResult
    {
        $bin = $ctx->row->meta['bin'] ?? null;
        return $bin && ChaseBins::where('bin', $bin)->exists()
            ? GuardResult::skip('chase_bin_deferred')
            : GuardResult::pass();
    }
}
```

Register it and add its key to the relevant types:

```php
// service provider
$this->app->make(GuardRunner::class)->register('chase_bin', ChaseBinGuard::class);
// config: types.rebill.guards => [..., 'chase_bin']
```

Unregistered keys in a `guards` array are silently ignored (so a shared config can list a
guard a particular vertical hasn't registered without erroring).

See `examples/CustomGuard.php`.

## How the runner works

`GuardRunner` holds a `key → class` registry (the six built-ins are registered in the
service provider). `run($ctx, $keys)` resolves each listed guard from the container (so
guards can have injected dependencies — `MidCap` takes a `MidResolver`, `AlreadyAttempted`
an `AttemptLogger`) and returns the first non-pass result.
