<?php

namespace Omni\BillingEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Models\BillingSchedule;

/**
 * Backfill / enrolment: materialise a billing population into
 * billing_schedule_{vertical}, WITHOUT double billing anyone. Re-runnable
 * (insertOrIgnore on the unique key), so it is safe to run:
 *   - ONCE for recurring rebills (the standing population), and
 *   - DAILY on a schedule for one-shot settle/convert enrolment (new auths
 *     crossing their +N-day mark each day).
 *
 *   billing:seed-schedule {vertical} [--type=settle] [--dry-run] [--spread-hours=6]
 *
 * Vertical-specific SQL lives in the app, exposed as a generator closure bound
 * to 'billing.seedSource'. It yields candidate arrays:
 *   ['member_id','billing_type','card_type','source_tr_id','mid_id','amount',
 *    'meta' (array),
 *    // recurring (rebill): the engine computes the due date + key
 *    'anchor_date','already_billed_this_cycle' (bool),'dead' (bool),
 *    // one-shot (settle/convert): the source dictates BOTH explicitly
 *    'next_action_at' (exact due = auth_date + N days),
 *    'idempotency_key' ({member}:{type}:{source_tr_id} — per-auth, NOT per-cycle)]
 *
 * When the source supplies `next_action_at` / `idempotency_key`, they are used
 * verbatim; otherwise the engine falls back to cycle math + the cycle key. The
 * engine always owns backlog spread, insertOrIgnore, and the parity counts.
 */
class SeedScheduleCommand extends Command
{
    protected $signature = 'billing:seed-schedule
        {vertical : The vertical/stack to seed}
        {--type=* : Limit to these billing types}
        {--dry-run : Compute and report, write nothing}
        {--spread-hours=6 : Spread overdue backlog across N hours}
        {--refresh-meta : Do NOT insert; merge fresh source meta (bin/ip/zip/…) onto EXISTING rows by idempotency_key}';

    protected $description = 'Backfill billing_schedule from the legacy ledger (idempotent, re-runnable)';

    public function handle(): int
    {
        $vertical = $this->argument('vertical');
        $dryRun   = (bool) $this->option('dry-run');
        $spread   = max(1, (int) $this->option('spread-hours')) * 3600;

        if (!app()->bound('billing.seedSource')) {
            $this->error("No 'billing.seedSource' provider bound. See examples/AppServiceProviderWiring.php.");
            return self::FAILURE;
        }

        $cycleDays = (int) config('billing-engine.cycle_days', 30);
        $now       = Carbon::now();
        $cycle     = $now->format('Ym');

        $stats = ['seen' => 0, 'pending' => 0, 'deferred' => 0, 'dead' => 0, 'inserted' => 0, 'duplicate' => 0, 'refreshed' => 0];
        $overdue = 0;

        $source = app('billing.seedSource'); // generator: yields candidate arrays
        $buffer = [];

        foreach ($source($vertical, (array) $this->option('type')) as $row) {
            $stats['seen']++;

            $type   = $row['billing_type'];
            $status = BillingSchedule::STATUS_PENDING;

            // Due date: explicit from the source (one-shot settle/convert:
            // auth_date + N days), else cycle math off the anchor (recurring).
            if (!empty($row['next_action_at'])) {
                $dueAt = Carbon::parse($row['next_action_at']);
            } else {
                $typeCycleDays = (int) config("billing-engine.types.{$type}.cycle_days", $cycleDays);
                $dueAt = Carbon::parse($row['anchor_date'] ?? $now->toDateTimeString())->addDays($typeCycleDays);
            }

            if (!empty($row['dead'])) {
                $status = BillingSchedule::STATUS_DEAD;
                $stats['dead']++;
            } elseif (!empty($row['already_billed_this_cycle'])) {
                // Safety interlock: push to NEXT cycle so cutover never re-bills.
                $dueAt  = $dueAt->lessThanOrEqualTo($now) ? $now->copy()->addDays($cycleDays) : $dueAt;
                $stats['deferred']++;
            } else {
                $stats['pending']++;
            }

            // Spread the genuine backlog so a run doesn't flood the gateway.
            if ($status === BillingSchedule::STATUS_PENDING && $dueAt->lessThan($now)) {
                $dueAt = $now->copy()->addSeconds(($overdue++ * $spread) % $spread + random_int(0, 300));
            }

            // Key: explicit per-auth (one-shot) else the per-cycle rebill key.
            $idempotencyKey = $row['idempotency_key'] ?? "{$row['member_id']}:{$type}:{$cycle}";

            $buffer[] = [
                'member_id'       => $row['member_id'],
                'billing_type'    => $type,
                'card_type'       => $row['card_type'] ?? 'cc',
                'source_tr_id'    => $row['source_tr_id'] ?? null,
                'mid_id'          => $row['mid_id'] ?? null,
                'amount'          => $row['amount'],
                'next_action_at'  => $dueAt,
                'status'          => $status,
                'cycle'           => $cycle,
                'idempotency_key' => $idempotencyKey,
                'meta'            => json_encode($row['meta'] ?? []),
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            if (count($buffer) >= 1000) {
                $this->flush($buffer, $dryRun, $stats);
                $buffer = [];
            }
        }

        $this->flush($buffer, $dryRun, $stats);

        $this->table(['metric', 'count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values());

        if ($this->option('refresh-meta')) {
            $this->info($dryRun ? 'DRY RUN — no meta written.' : "Meta refresh complete ({$stats['refreshed']} rows updated).");
        } else {
            $this->info($dryRun ? 'DRY RUN — nothing written.' : 'Seed complete.');
            $this->line('Parity gate: compare "pending" against the legacy query population before cutover.');
        }

        return self::SUCCESS;
    }

    private function flush(array $buffer, bool $dryRun, array &$stats): void
    {
        if (empty($buffer)) {
            return;
        }

        $conn  = config('billing-engine.schedule.connection');
        $table = config('billing-engine.schedule.table');

        // --refresh-meta: update EXISTING rows' meta in place, never insert.
        if ($this->option('refresh-meta')) {
            $this->refreshMeta($buffer, $dryRun, $stats, $conn, $table);
            return;
        }

        if ($dryRun) {
            return;
        }

        // insertOrIgnore on the UNIQUE idempotency key → safe to re-run.
        $before = DB::connection($conn)->table($table)->count();
        DB::connection($conn)->table($table)->insertOrIgnore($buffer);
        $after  = DB::connection($conn)->table($table)->count();

        $inserted = $after - $before;
        $stats['inserted']  += $inserted;
        $stats['duplicate'] += count($buffer) - $inserted;
    }

    /**
     * Merge freshly-generated source meta (bin/ip/zip/country/…) onto EXISTING
     * schedule rows, matched by the same idempotency_key the seed produced — so a
     * row gets exactly the values from the source row it was seeded from, no
     * cross-DB JOIN. JSON_MERGE_PATCH only overwrites the keys present in the
     * patch, so runtime keys (claim_run, mid_strategy) are preserved. Idempotent.
     */
    private function refreshMeta(array $buffer, bool $dryRun, array &$stats, ?string $conn, string $table): void
    {
        $db = DB::connection($conn);

        foreach ($buffer as $r) {
            if (empty($r['idempotency_key'])) {
                continue;
            }

            if ($dryRun) {
                $stats['refreshed'] += $db->table($table)
                    ->where('idempotency_key', $r['idempotency_key'])->exists() ? 1 : 0;
                continue;
            }

            $ts = $r['updated_at'] instanceof \DateTimeInterface
                ? $r['updated_at']->format('Y-m-d H:i:s')
                : (string) $r['updated_at'];

            $stats['refreshed'] += $db->update(
                "UPDATE `{$table}` SET meta = JSON_MERGE_PATCH(COALESCE(meta, '{}'), ?), updated_at = ? WHERE idempotency_key = ?",
                [$r['meta'], $ts, $r['idempotency_key']]
            );
        }
    }
}
