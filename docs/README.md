# Billing Engine — Documentation

Detailed reference for the `omni/billing-engine` package. Start with
[Architecture](architecture.md) for the big picture, then dive into the area you need.

## Index

| Doc | What it covers |
|---|---|
| [architecture.md](architecture.md) | The problem, the three-database context, the pipeline, design principles |
| [data-model.md](data-model.md) | `billing_schedule_{vertical}` schema, statuses, the row lifecycle, idempotency |
| [handlers.md](handlers.md) | The `BillingHandler` template method, its hooks, and the four shipped handlers |
| [guards.md](guards.md) | The guard pipeline, the six built-in guards, writing your own |
| [step-downs.md](step-downs.md) | Decline-code-driven step-down ladders for rebills, crosses, and conversions |
| [mid-resolution.md](mid-resolution.md) | `MidResolver`, the configurable MID source table, the mid-balancer adapter |
| [gateways.md](gateways.md) | `GatewayClient`, NMI/Inovio adapters, the canonical payload, `GatewayResult` |
| [dispatcher-and-jobs.md](dispatcher-and-jobs.md) | The atomic claim, `ProcessBillingJob`, the queue, retries, throttling |
| [backfill.md](backfill.md) | `billing:seed-schedule`, the seed-source provider, the parity gate, staggering |
| [configuration.md](configuration.md) | Every config key in `config/billing-engine.php` |
| [extending.md](extending.md) | The layered extensibility model — which mechanism for which difference |
| [migrating-a-vertical.md](migrating-a-vertical.md) | Step-by-step cutover from the legacy command |
| [events.md](events.md) | The five billing events and how to listen |

## Conventions used in these docs

- **vertical / stack** — one of the six product lines (sports, pdf, manuals, …). Each is a
  separate Laravel app that installs this package.
- **legacy command** — the existing `NMIBilling` / `InovioBilling` Artisan command this
  engine replaces, method by method.
- **`bigentertainment`** — the default DB connection (MID config + mid-balancer state).
- **`omnistats`** — the legacy DB (transaction ledger + attempt logs).
