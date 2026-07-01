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
| `negative_db` | `NegativeDb` | DEAD with reason | `billing.negativeDb` closure |
| `max_declines` | `MaxDeclines` | DEAD at the decline ceiling | `billing.declineCount` closure |
| `mid_cap` | `MidCap` | SKIP if no usable sticky MID | `MidResolver` |
| `conversion_rebill` | `ConversionRebill` | SKIP if already converted/rebilled this window | `billing.conversionRebill` closure |

### Why closures for the data-driven guards

`SameDay`, `NegativeDb`, `MaxDeclines`, and `ConversionRebill` need vertical-specific SQL
against tables the package shouldn't know about (the ledger, fraud lists, etc.). Rather than
hardcode those queries, each resolves an **app-bound closure** if present, and passes
through (`pass()`) if not. The app binds them in a service provider:

```php
$this->app->instance('billing.sameDayCheck', fn (string $memberId): bool => /* ... */);
$this->app->instance('billing.negativeDb',  fn (string $memberId): ?string => /* reason|null */);
$this->app->instance('billing.declineCount', fn (string $memberId, string $type): int => /* ... */);
$this->app->instance('billing.conversionRebill', fn (string $memberId): bool => /* ... */);
```

This keeps the package free of any vertical's schema while preserving the exact legacy
checks.

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
