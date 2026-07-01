<?php

namespace Omni\BillingEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Jobs\ProcessBillingJob;
use Omni\BillingEngine\Models\BillingSchedule;
use Omni\BillingEngine\Preview\BillingPreviewer;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\PreviewResult;

/**
 * Lightweight dispatcher. Atomically CLAIMS a batch of due rows and fans out
 * one job per row, then exits in seconds. Nothing long-held — schedule it
 * every 1–2 min with ->withoutOverlapping()->expiresAt(short).
 *
 *   billing:dispatch [--type=rebill] [--limit=500]
 *
 * Dry run — show who WOULD be charged without claiming, charging, or writing
 * anything (run the real guards + MID resolution, stop before the charge):
 *
 *   billing:dispatch --type=rebill --dry-run
 *   billing:dispatch --type=rebill --dry-run --out=storage/rebill-preview.csv
 */
class DispatchCommand extends Command
{
    protected $signature = 'billing:dispatch
        {--type=* : Limit to these billing types}
        {--limit= : Max rows to claim/preview (defaults to dispatch.claim_batch; unlimited in --dry-run)}
        {--dry-run : Preview who WOULD be charged; claim nothing, charge nothing, write nothing}
        {--out= : With --dry-run, write the full per-member preview to this CSV path}';

    protected $description = 'Claim due billing rows and dispatch processing jobs (or --dry-run to preview)';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $limit   = (int) ($this->option('limit') ?: config('billing-engine.dispatch.claim_batch', 500));
        $types   = (array) $this->option('type');
        $conn    = config('billing-engine.schedule.connection');
        $table   = config('billing-engine.schedule.table');
        $now     = Carbon::now();
        $claimId = $now->format('YmdHis') . '-' . getmypid(); // marker for this run

        // 1. Atomic claim: flip a batch of due 'pending' rows to 'claimed'.
        $claim = DB::connection($conn)->table($table)
            ->where('status', BillingSchedule::STATUS_PENDING)
            ->where('next_action_at', '<=', $now)
            ->when($types, fn ($q) => $q->whereIn('billing_type', $types))
            ->orderBy('next_action_at')
            ->limit($limit);

        $claimed = (clone $claim)->update([
            'status'     => BillingSchedule::STATUS_CLAIMED,
            'claimed_at' => $now,
            'meta'       => DB::raw("JSON_SET(COALESCE(meta,'{}'), '$.claim_run', '{$claimId}')"),
        ]);

        if ($claimed === 0) {
            $this->info('No due rows.');
            return self::SUCCESS;
        }

        // 2. Fan out one job per freshly-claimed row.
        $ids = DB::connection($conn)->table($table)
            ->where('status', BillingSchedule::STATUS_CLAIMED)
            ->where('claimed_at', $now)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessBillingJob::dispatch((int) $id);
        }

        $this->info("Claimed and dispatched {$ids->count()} rows.");
        return self::SUCCESS;
    }

    /**
     * Read-only preview of the next dispatch: walk every due row through the
     * real guard pipeline + MID resolution and report what WOULD happen, never
     * claiming, charging, or writing. Safe to run against live data.
     */
    private function dryRun(): int
    {
        $types = (array) $this->option('type');
        $now   = Carbon::now();

        /** @var BillingPreviewer $previewer */
        $previewer = app(BillingPreviewer::class);

        // Same "due?" predicate as the real claim. No --limit ⇒ preview the
        // whole due population (that is the point of a pre-cutover check).
        $query = BillingSchedule::query()
            ->where('status', BillingSchedule::STATUS_PENDING)
            ->where('next_action_at', '<=', $now)
            ->when($types, fn ($q) => $q->whereIn('billing_type', $types))
            ->orderBy('next_action_at');

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $due = $query->get();

        if ($due->isEmpty()) {
            $this->info('No due rows — nothing would be charged.');
            return self::SUCCESS;
        }

        $this->line('<comment>DRY RUN</comment> — no rows claimed, no charges sent, nothing written.');
        $this->newLine();

        $summary    = [
            PreviewResult::CHARGE => ['count' => 0, 'amount' => 0.0],
            PreviewResult::SKIP   => ['count' => 0, 'amount' => 0.0],
            PreviewResult::DEAD   => ['count' => 0, 'amount' => 0.0],
            PreviewResult::NO_MID => ['count' => 0, 'amount' => 0.0],
        ];
        $reasonTally = [];      // reason => count, for skips/deads/no-mid
        $sampleRows  = [];      // first N for console
        $csv         = $this->openCsv();

        foreach ($due as $row) {
            $config = config("billing-engine.types.{$row->billing_type}", []);
            $ctx    = new BillingContext($row, config('billing-engine.vertical'), $row->billing_type, $config);

            $result = $previewer->preview($ctx);

            $summary[$result->disposition]['count']++;
            $summary[$result->disposition]['amount'] += $result->willCharge() ? (float) $row->amount : 0.0;

            if (!$result->willCharge() && $result->reason) {
                $reasonTally[$result->reason] = ($reasonTally[$result->reason] ?? 0) + 1;
            }

            $line = [
                $row->member_id,
                $row->billing_type,
                $row->card_type,
                number_format((float) $row->amount, 2),
                (int) ($row->step ?? 0),
                strtoupper($result->disposition),
                $result->mid?->midId ?? '—',
                $result->reason ?? '',
            ];

            if (count($sampleRows) < 25) {
                $sampleRows[] = $line;
            }

            if ($csv) {
                fputcsv($csv, $line);
            }
        }

        if ($csv) {
            fclose($csv);
        }

        $this->renderSample($sampleRows, $due->count());
        $this->renderSummary($summary, $reasonTally);

        if ($out = $this->option('out')) {
            $this->info("Full per-member preview written to {$out}");
        } else {
            $this->line('<comment>Tip:</comment> add --out=path.csv to export the full per-member list.');
        }

        return self::SUCCESS;
    }

    /** @return resource|null */
    private function openCsv()
    {
        $out = $this->option('out');
        if (!$out) {
            return null;
        }

        $handle = fopen($out, 'w');
        if ($handle === false) {
            $this->warn("Could not open --out path for writing: {$out} (continuing without CSV).");
            return null;
        }

        fputcsv($handle, ['member_id', 'billing_type', 'card_type', 'amount', 'step', 'disposition', 'mid_id', 'reason']);
        return $handle;
    }

    private function renderSample(array $rows, int $total): void
    {
        $this->table(
            ['member', 'type', 'card', 'amount', 'step', 'disposition', 'mid', 'reason'],
            $rows
        );

        if ($total > count($rows)) {
            $this->line(sprintf('  … showing first %d of %d due rows. Use --out for the full list.', count($rows), $total));
        }

        $this->newLine();
    }

    private function renderSummary(array $summary, array $reasonTally): void
    {
        $wouldCharge = $summary[PreviewResult::CHARGE];

        $this->table(['disposition', 'members', 'total $'], [
            ['<info>WOULD CHARGE</info>', $wouldCharge['count'], number_format($wouldCharge['amount'], 2)],
            ['would skip',               $summary[PreviewResult::SKIP]['count'],   '—'],
            ['would be marked dead',     $summary[PreviewResult::DEAD]['count'],   '—'],
            ['no usable MID (deferred)', $summary[PreviewResult::NO_MID]['count'], '—'],
        ]);

        if ($reasonTally) {
            arsort($reasonTally);
            $this->line('Reasons (not charged):');
            foreach ($reasonTally as $reason => $count) {
                $this->line(sprintf('  %-28s %d', $reason, $count));
            }
            $this->newLine();
        }

        $this->info(sprintf(
            '%d members would be charged a total of %s. Nothing was charged or written.',
            $wouldCharge['count'],
            number_format($wouldCharge['amount'], 2)
        ));
    }
}
