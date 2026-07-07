<?php

/**
 * EXAMPLE — the sports backfill / enrolment source generator. Vertical-specific
 * SQL lives HERE; the package's billing:seed-schedule command owns dedup→
 * idempotency→stagger→insertOrIgnore and never reads these tables itself.
 *
 * Two shapes of population:
 *
 *   RECURRING (rebill) — seed ONCE; the engine computes due date + cycle key:
 *     - rebillCC       → transactions ledger, tui_udf02 = cc set    card cc
 *     - rebillPP       → transactions ledger, tui_udf02 = pp set    card pp
 *     - rebillSettles  → tickets table,       tr_amount in amounts  card cc
 *   (all three yield billing_type 'rebill'.)
 *
 *   ONE-SHOT (settle/convert) — run DAILY on a schedule; the source dictates the
 *   exact due date (auth_date + N days) and a PER-AUTH idempotency key so a
 *   member's many auths never collapse and re-runs are idempotent:
 *     - settle  → auth-only table, settle amounts → doCapture(tr_id)   +2 days
 *     - convert → auth-only table, $0 auth        → charge convert amt +5 days
 *
 * Bind: app()->instance('billing.seedSource', new SeedSourceProvider());
 *
 * Schedule (app Kernel), each on its own cadence:
 *   $schedule->command('billing:seed-schedule sports --type=settle')->hourly();
 *   $schedule->command('billing:seed-schedule sports --type=convert')->daily();
 */

namespace App\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SeedSourceProvider
{
    /** Attempt-log window == one cycle (matches legacy DATE(date) BETWEEN -29d AND today). */
    private const CYCLE_DAYS = 30;

    public function __invoke(string $vertical, array $types = []): iterable
    {
        $want = fn (string $t) => !$types || in_array($t, $types, true);

        // REBILLS ONLY. A member's FIRST paid charge is a CONVERSION
        // (convertInitials/convertPP) or a SETTLE capture (settleAuths) — the first
        // transaction, handled by the LEGACY crons (out of scope). The engine seeds
        // only 2nd-and-onward charges (rebills), anchored on the member's LATEST
        // paid transaction + 30 days. The CC/PP sources include the CONVERSION udf2
        // codes (CCC/PPC) because the FIRST rebill anchors on the conversion row;
        // later rebills anchor on the prior rebill (CCR/PPR). settlesRebill anchors
        // on the capture ticket.
        if ($want('rebill')) {
            yield from $this->cardTransactions('cc');   // rebillCC
            yield from $this->cardTransactions('pp');   // rebillPP
            yield from $this->settlesRebill();          // rebillSettles (a rebill)
        }

        // 'settle'/'convert' are NOT seeded — those are the first-charge conversion
        // flows, migrated as a separate project. settleCaptures()/conversions()
        // below are kept unused for reference; do NOT wire them to a cron.
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

    /* ---- RECURRING: rebill populations (seed once) -------------------------- */

    /**
     * rebillCC / rebillPP — the LATEST approved rebill-eligible auth per member.
     *
     * No GROUP BY: order by member then tr_date DESC and take the first row per
     * member in PHP (see latestPerMember). This is strict-mode safe
     * (ONLY_FULL_GROUP_BY) AND correct — every column (tr_id/mid_id/bin/exp) comes
     * from that member's actual latest row, not an arbitrary grouped one.
     */
    private function cardTransactions(string $cardType): iterable
    {
        $query = DB::connection($this->conn())
            ->table($this->table('transactions', 'auth_transactions_sports'))
            ->select($this->columns())
            ->addSelect('tr_date as anchor_date')
            ->where('resp_id', 0)
            ->whereIn('tui_udf02', $this->udf2($cardType))
            ->whereNull('k1')
            ->orderBy('cust_id_ext')
            ->orderByDesc('tr_date');

        foreach ($this->latestPerMember($query) as $r) {
            yield $this->recurring($r, $cardType, (float) $r->amount);
        }
    }

    /** rebillSettles — members whose latest ticket amount is a settle amount (a rebill). */
    private function settlesRebill(): iterable
    {
        $query = DB::connection($this->conn())
            ->table($this->table('tickets', 'auth_tickets_sports'))
            ->select($this->columns())
            ->addSelect('tr_date as anchor_date')
            ->where('resp_id', 0)
            ->whereIn('tr_amount', $this->settleAmounts())
            ->whereNull('k1')
            ->orderBy('cust_id_ext')
            ->orderByDesc('tr_date');

        foreach ($this->latestPerMember($query) as $r) {
            yield $this->recurring($r, 'cc', (float) $r->amount);
        }
    }

    /**
     * Stream a member-ordered, tr_date-DESC query and yield only the FIRST row
     * per member (= their latest). Replaces GROUP BY: strict-mode safe, correct,
     * and memory-safe via cursor(). Assumes the query is ordered by member then
     * tr_date DESC. For speed on large tables, index (cust_id_ext, tr_date).
     */
    private function latestPerMember($query): iterable
    {
        $lastMember = null;

        foreach ($query->cursor() as $r) {
            if ($r->member_id === $lastMember) {
                continue; // already took this member's latest row
            }
            $lastMember = $r->member_id;
            yield $r;
        }
    }

    /* ---- ONE-SHOT: settle + convert enrolment (run daily) ------------------- */

    /**
     * settleAuths — enrol each open full auth as a one-shot capture, due
     * auth_date + settle_after_days. Keyed per auth tr_id so daily re-runs only
     * add new auths (insertOrIgnore skips the rest).
     */
    private function settleCaptures(): iterable
    {
        $days = (int) config('billing-engine.seed.settle_after_days', 2);

        $rows = DB::connection($this->conn())
            ->table($this->table('auths', 'auth_only_sports'))
            ->where('resp_id', 0)
            ->whereIn('tr_amount', $this->settleAmounts())
            ->whereNull('k1')
            ->orderBy('tr_date_only')
            ->cursor();

        foreach ($rows as $r) {
            yield $this->oneShot($r, 'settle', 'cc', (float) $r->tr_amount, $r->merchant_account, $days);
        }
    }

    /**
     * convertInitials — enrol each $0 auth as a one-shot conversion charge, due
     * auth_date + convert_days, at the configured convert amount. mid_id null →
     * the handler's rotation resolver (mid-balancer trial pool) picks at charge.
     */
    private function conversions(): iterable
    {
        $days   = (int) config('billing-engine.seed.convert_days', 5);
        $amount = (float) config('billing-engine.seed.convert_amount', 29.55);

        $rows = DB::connection($this->conn())
            ->table($this->table('auths', 'auth_only_sports'))
            ->where('resp_id', 0)
            ->where('tr_amount', 0)                 // $0 initial auth
            ->whereNull('k1')
            ->orderBy('tr_date_only')
            ->cursor();

        foreach ($rows as $r) {
            yield $this->oneShot($r, 'convert', 'cc', $amount, null, $days);
        }
    }

    /* ---- shaping ------------------------------------------------------------ */

    /** Columns every source selects (member attributes + charge context). */
    private function columns(): array
    {
        return [
            'cust_id_ext as member_id',
            'tr_id as source_tr_id',
            'merchant_account as mid_id',
            'tr_amount as amount',
            'bankbin', 'tui_ip', 'tui_bill_zip', 'tui_bill_country', 'affiliate', 'tui_udf01',
            'expiremonth', 'expireyear',
        ];
    }

    /** RECURRING candidate: engine computes due date + cycle key. */
    private function recurring(object $r, string $cardType, float $amount): array
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
            'meta'                      => $this->meta($r),
        ];
    }

    /**
     * ONE-SHOT candidate: source dictates the exact due date (auth_date + N) and
     * a per-auth idempotency key. $r here is a raw auth row (member_id/source_tr_id
     * come straight off the columns, not aliased via GROUP BY).
     */
    private function oneShot(object $r, string $type, string $cardType, float $amount, $midId, int $afterDays): array
    {
        return [
            'member_id'       => $r->cust_id_ext,
            'billing_type'    => $type,
            'card_type'       => $cardType,
            'source_tr_id'    => $r->tr_id,                                   // the auth to capture/convert
            'mid_id'          => $midId,                                      // null for convert (rotation)
            'amount'          => $amount,
            'next_action_at'  => Carbon::parse($r->tr_date)->addDays($afterDays),
            'idempotency_key' => "{$r->cust_id_ext}:{$type}:{$r->tr_id}",     // per-auth, NOT per-cycle
            'meta'            => $this->meta($r),
        ];
    }

    /** meta travels onto the schedule row (no per-charge ledger hit). */
    private function meta(object $r): array
    {
        return array_filter([
            'bin'      => $r->bankbin ?? null,
            'ip'       => $r->tui_ip ?? null,
            'zip'      => $r->tui_bill_zip ?? null,
            'country'  => $r->tui_bill_country ?? null,
            'device'   => $r->affiliate ?? null,
            'udf_1'    => $r->tui_udf01 ?? null,
            'card_exp' => $this->cardExp($r),
        ], fn ($v) => $v !== null && $v !== '');
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
