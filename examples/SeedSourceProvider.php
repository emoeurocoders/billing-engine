<?php

/**
 * EXAMPLE — the sports backfill source generator. Vertical-specific SQL lives
 * HERE; the package's billing:seed-schedule command owns dedup→idempotency→
 * stagger→insertOrIgnore and never reads these ledger tables itself.
 *
 * Yields ONE row per member per billing_type, shaped as documented in
 * SeedScheduleCommand:
 *   ['member_id','billing_type','card_type','source_tr_id','mid_id','amount',
 *    'anchor_date','already_billed_this_cycle','dead','meta']
 *
 * Covers the three legacy rebill populations, all collapsed onto billing_type
 * 'rebill' (they share the same handler; only selection differs):
 *   - rebillCC       → transactions ledger, tui_udf02 = cc set    card cc
 *   - rebillPP       → transactions ledger, tui_udf02 = pp set    card pp
 *   - rebillSettles  → tickets table,       tr_amount in amounts  card cc
 *
 * TABLE NAMES / FILTERS ARE CONFIG, not hardcoded — read from
 * `billing-engine.seed`. Wiring this in another app is a config change, not a
 * code change: point `seed.connection`, `seed.sources.*`, `seed.udf2.*` and
 * `seed.settle_amounts` at that app's tables/values (the fallbacks below are the
 * sports defaults for when the config block is absent).
 *
 * Bind: app()->instance('billing.seedSource', new SeedSourceProvider());
 */

namespace App\Billing;

use Illuminate\Support\Facades\DB;

class SeedSourceProvider
{
    /** Attempt-log window == one cycle (matches legacy DATE(date) BETWEEN -29d AND today). */
    private const CYCLE_DAYS = 30;

    public function __invoke(string $vertical, array $types = []): iterable
    {
        // All three populations are billing_type 'rebill'.
        if ($types && !in_array('rebill', $types, true)) {
            return;
        }

        yield from $this->cardTransactions('cc');   // rebillCC
        yield from $this->cardTransactions('pp');   // rebillPP
        yield from $this->settles();                // rebillSettles
    }

    /* ---- config accessors (each table/filter is overridable per app) -------- */

    private function conn(): ?string
    {
        return config('billing-engine.seed.connection', 'omnistats');
    }

    private function table(string $key, string $default): string
    {
        return config("billing-engine.seed.sources.{$key}", $default);
    }

    private function udf2(string $card): array
    {
        return config("billing-engine.seed.udf2.{$card}", $card === 'pp' ? ['PPC', 'PPR'] : ['CCC', 'CCR']);
    }

    private function settleAmounts(): array
    {
        return config('billing-engine.seed.settle_amounts', [34.55, 29.55, 19.55]);
    }

    /* ---- populations -------------------------------------------------------- */

    /**
     * rebillCC / rebillPP — latest approved rebill-eligible auth per member.
     * MAX(tr_date) gives the anchor for next_action_at; the member-attribute
     * columns (bin/ip/country/exp) are stable across a member's rows.
     */
    private function cardTransactions(string $cardType): iterable
    {
        $rows = DB::connection($this->conn())
            ->table($this->table('transactions', 'auth_transactions_sports'))
            ->select([
                'cust_id_ext as member_id',
                'tr_id as source_tr_id',
                'merchant_account as mid_id',
                'tr_amount as amount',
                'bankbin', 'tui_ip', 'tui_bill_country', 'affiliate', 'tui_udf01',
                'expiremonth', 'expireyear',
                DB::raw('MAX(tr_date) as anchor_date'),
            ])
            ->where('resp_id', 0)
            ->whereIn('tui_udf02', $this->udf2($cardType))
            ->whereNull('k1')                       // exclude dead-flagged (k1)
            ->groupBy('cust_id_ext')
            ->orderBy('cust_id_ext')
            ->cursor();                             // stream — never load 5M rows

        foreach ($rows as $r) {
            yield $this->candidate($r, $cardType, (float) $r->amount);
        }
    }

    /**
     * rebillSettles — members whose ticket amount is one of the settle amounts,
     * rebilled at that ticket amount.
     */
    private function settles(): iterable
    {
        $rows = DB::connection($this->conn())
            ->table($this->table('tickets', 'auth_tickets_sports'))
            ->select([
                'cust_id_ext as member_id',
                'tr_id as source_tr_id',
                'merchant_account as mid_id',
                'tr_amount as amount',
                'bankbin', 'tui_ip', 'tui_bill_country', 'affiliate', 'tui_udf01',
                'expiremonth', 'expireyear',
                DB::raw('MAX(tr_date) as anchor_date'),
            ])
            ->where('resp_id', 0)
            ->whereIn('tr_amount', $this->settleAmounts())
            ->whereNull('k1')
            ->groupBy('cust_id_ext')
            ->orderBy('cust_id_ext')
            ->cursor();

        foreach ($rows as $r) {
            // Settles carry the ticket's own amount (legacy logged tr_amount).
            yield $this->candidate($r, 'cc', (float) $r->amount);
        }
    }

    /* ---- shaping ------------------------------------------------------------ */

    /** Shape one candidate row + its meta, and reconcile the current cycle. */
    private function candidate(object $r, string $cardType, float $amount): array
    {
        return [
            'member_id'                 => $r->member_id,
            'billing_type'              => 'rebill',
            'card_type'                 => $cardType,
            'source_tr_id'              => $r->source_tr_id,
            'mid_id'                    => $r->mid_id,
            'amount'                    => $amount,
            'anchor_date'               => $r->anchor_date,
            'already_billed_this_cycle' => $this->alreadyBilled($r->member_id),
            'dead'                      => false,
            'meta'                      => array_filter([
                'bin'      => $r->bankbin ?? null,
                'ip'       => $r->tui_ip ?? null,
                'country'  => $r->tui_bill_country ?? null,
                'device'   => $r->affiliate ?? null,
                'udf_1'    => $r->tui_udf01 ?? null,
                'card_exp' => $this->cardExp($r),
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** MMYY, month zero-padded. */
    private function cardExp(object $r): ?string
    {
        if (empty($r->expiremonth) || empty($r->expireyear)) {
            return null;
        }

        return str_pad((string) $r->expiremonth, 2, '0', STR_PAD_LEFT) . $r->expireyear;
    }

    /** Any attempt on the attempts table this cycle → park to next cycle at seed time. */
    private function alreadyBilled($memberId): bool
    {
        return DB::connection($this->conn())
            ->table($this->table('attempts', 'rebill_sports'))
            ->where('uid', $memberId)
            ->whereBetween(DB::raw('DATE(`date`)'), [
                now()->subDays(self::CYCLE_DAYS - 1)->toDateString(),
                now()->toDateString(),
            ])
            ->exists();
    }
}
