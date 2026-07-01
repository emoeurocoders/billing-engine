<?php

/**
 * EXAMPLE — the backfill source generator. Vertical-specific SQL lives HERE;
 * the package's billing:seed-schedule command owns dedup→idempotency→stagger.
 *
 * It must be invokable as: ($vertical, array $types) => iterable<array>, yielding
 * ONE row per member per billing_type (the member's latest success), shaped as
 * documented in SeedScheduleCommand.
 *
 * Bind: app()->instance('billing.seedSource', new SeedSourceProvider());
 */

namespace App\Billing;

use Illuminate\Support\Facades\DB;

class SeedSourceProvider
{
    public function __invoke(string $vertical, array $types = []): iterable
    {
        // Rebills: one latest approved signup/rebill auth per member from the
        // ledger. Group + MAX(tr_date) gives the anchor for next_action_at.
        $rows = DB::connection('omnistats')
            ->table('auth_transactions_sports')
            ->select([
                'cust_id_ext as member_id',
                DB::raw("'rebill' as billing_type"),
                DB::raw("LOWER(card_type) as card_type"),
                'tr_id as source_tr_id',
                'merchant_account as mid_id',
                'tr_amount as amount',
                DB::raw('MAX(tr_date) as anchor_date'),
            ])
            ->where('resp_id', 0)
            ->whereIn('tui_udf02', ['CCC', 'CCR'])
            ->whereNull('k1')                       // not already marked dead
            ->groupBy('cust_id_ext')
            ->orderBy('cust_id_ext')
            ->cursor();                             // stream — never load 5M rows

        foreach ($rows as $r) {
            // Reconcile against the legacy attempt log for the current cycle.
            $alreadyBilled = DB::connection('omnistats')->table('rebill_sports')
                ->where('uid', $r->member_id)
                ->whereBetween(DB::raw('DATE(`date`)'), [now()->subDays(29)->toDateString(), now()->toDateString()])
                ->exists();

            yield [
                'member_id'                 => $r->member_id,
                'billing_type'              => 'rebill',
                'card_type'                 => $r->card_type ?: 'cc',
                'source_tr_id'              => $r->source_tr_id,
                'mid_id'                    => $r->mid_id,
                'amount'                    => $r->amount,
                'anchor_date'               => $r->anchor_date,
                'already_billed_this_cycle' => $alreadyBilled,
                'dead'                      => false,
                'meta'                      => ['udf_1' => null],
            ];
        }

        // Repeat with cross1/cross2/convert/settle source queries as needed,
        // filtered by $types when provided.
    }
}
