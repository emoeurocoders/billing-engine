<?php

namespace Omni\BillingEngine\Guards;

use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Negative-database / hard-decline gate. Faithful port of the legacy
 * NMIBilling::negativeDb() (the do-not-bill list). A hit returns DEAD so the row
 * is never retried — matching the legacy behaviour of writing k1=2 on the ledger.
 *
 * Checked in the SAME order as legacy (first hit wins):
 *   1. credit      — member has a non-VOID credit/refund (amount != 2) in-product
 *   2. chargeback  — member has a chargeback
 *   3. cancel      — member is in the cancels table
 *   4. bin_block   — the member's card BIN is blocked
 *   5. block       — member is in the blocked-members table
 *   6. hard_decline (in-product) — a prior hard-decline code on this product
 *   7. hard_decline (stack)      — a stack-wide hard-decline code on any product
 *   8. blacklisted_geo           — conversions only, country on the block list
 *   9. max_declines              — declines since last approval >= ceiling
 *
 * EVERY connection, table name, code list and threshold is read from the
 * `billing-engine.negative_db` config block, because these tables differ per
 * vertical/app (sports → auth_credits_sports / sports_bin_block, pdf → the pdf
 * equivalents, ...). The constants below are only the fallback defaults used
 * when a key is absent from config; publish and override them per vertical.
 *
 * IMPORTANT — the legacy negativeDb() ALSO fired rescueDecline() (a real
 * FlexCharge charge) as a side effect when a member was over the decline
 * ceiling. That is deliberately NOT done here: a guard must be read-only (guards
 * also run under `billing:dispatch --dry-run`, where issuing a charge would bill
 * people during a preview). Wire rescue-decline as a post-decline step/listener.
 *
 * An app may still bind `billing.negativeDb` to replace the built-in checks
 * entirely (closure receives the BillingContext, returns a reason string or null).
 */
class NegativeDb implements BillingGuard
{
    /** Fallback defaults (sports). Anything set in config overrides these key-by-key. */
    private const DEFAULTS = [
        'connection' => 'omnistats',
        'tables' => [
            'credits'      => 'auth_credits_sports',
            'chargebacks'  => 'auth_chargebacks_sports',
            'cancels'      => 'cancels_sports',
            'bin_block'    => 'sports_bin_block',
            'blocked'      => 'blocked_members',
            'transactions' => 'auth_transactions_sports',
        ],
        // Column names also vary per app — overridable.
        'columns' => [
            'member'       => 'cust_id_ext',   // member id on credits/transactions
            'cb_member'    => 'customerid',     // member id on chargebacks
            'cancel_member'=> 'member_id',      // member id on cancels
            'block_member' => 'customer_id',    // member id on blocked table
            'bin'          => 'bin',            // bin column on bin_block table
            'resp'         => 'resp_id',        // response/decline code on transactions
            'udf'          => 'tui_udf02',      // product udf on credits/transactions
            'amount'       => 'tr_amount',      // amount on credits/transactions
            'date'         => 'tr_date',        // ordering column on transactions
            'ledger_bin'   => 'bankbin',        // bin column on the transactions ledger
            'credit_type'  => 'ttype_name',     // transaction-type column on credits
        ],
        'hard_decline'       => ['106', '107', '111', '112', '123', '164', '165', '201', '264', '460'],
        'hard_decline_stack' => ['111', '159'],
        'max_declines'       => 3,
        // Merchant-side failure codes (407 = Invalid merchant ID) — never the
        // cardholder's fault, so they don't count toward the decline ceiling.
        'decline_count_exclude' => ['407'],
        'product_udfs'       => [
            'cc' => ['CC', 'CCC', 'CCR'],
            'pp' => ['PP', 'PPC', 'PPR'],
        ],
        'blacklisted_geo'    => [],
    ];

    public function key(): string { return 'negative_db'; }

    public function check(BillingContext $ctx): GuardResult
    {
        // Escape hatch: an app-bound closure fully replaces the built-in checks.
        if (app()->bound('billing.negativeDb')) {
            $reason = app('billing.negativeDb')($ctx);
            return $reason ? GuardResult::dead("negative_db:{$reason}") : GuardResult::pass();
        }

        $reason = $this->evaluate($ctx);

        return $reason ? GuardResult::dead("negative_db:{$reason}") : GuardResult::pass();
    }

    /** @return string|null reason to hard-stop, or null to pass */
    private function evaluate(BillingContext $ctx): ?string
    {
        $cfg    = $this->config();
        $conn   = $cfg['connection'];
        $t      = $cfg['tables'];
        $c      = $cfg['columns'];
        $member = $ctx->memberId();
        $udfs   = $cfg['product_udfs'][$ctx->cardType()] ?? [];

        $table = fn (string $name) => DB::connection($conn)->table($t[$name]);

        // 1. Credit / refund on this product (excluding VOIDs and the $2 auth).
        if ($udfs && $table('credits')
                ->where($c['member'], $member)
                ->where($c['credit_type'], '!=', 'VOID')
                ->where($c['amount'], '!=', 2)
                ->whereIn($c['udf'], $udfs)
                ->exists()) {
            return 'credit';
        }

        // 2. Chargeback.
        if ($table('chargebacks')->where($c['cb_member'], $member)->exists()) {
            return 'chargeback';
        }

        // 3. Cancelled member.
        if ($table('cancels')->where($c['cancel_member'], $member)->exists()) {
            return 'cancel';
        }

        // 4. Blocked BIN.
        $bin = $this->bin($ctx, $conn, $t['transactions'], $c);
        if ($bin !== null && $bin !== '' && $table('bin_block')->where($c['bin'], $bin)->exists()) {
            return 'bin_block';
        }

        // 5. Blocked member.
        if ($table('blocked')->where($c['block_member'], $member)->exists()) {
            return 'block';
        }

        // 6. Prior hard-decline code on this product.
        $hard = array_map('strval', $cfg['hard_decline'] ?? []);
        if ($hard && $udfs && $table('transactions')
                ->where($c['member'], $member)
                ->whereIn($c['resp'], $hard)
                ->whereIn($c['udf'], $udfs)
                ->exists()) {
            return 'hard_decline';
        }

        // 7. Prior stack-wide hard-decline code on ANY product.
        $stack = array_map('strval', $cfg['hard_decline_stack'] ?? []);
        if ($stack && $table('transactions')
                ->where($c['member'], $member)
                ->whereIn($c['resp'], $stack)
                ->exists()) {
            return 'hard_decline';
        }

        // 8. Blacklisted geo — conversions only (legacy gated on billingType).
        $geo     = (array) ($cfg['blacklisted_geo'] ?? []);
        $country = $ctx->row->meta['country'] ?? ($ctx->row->meta['tui_bill_country'] ?? null);
        if ($geo && in_array($ctx->billingType, ['convert', 'conversion'], true)
                 && $country && in_array($country, $geo, true)) {
            return 'blacklisted_geo:' . $country;
        }

        // 9. Too many declines since the last approval (all products, amount > 2).
        $max     = (int) ($cfg['max_declines'] ?? 3);
        $exclude = array_map('strval', $cfg['decline_count_exclude'] ?? []);
        if ($max > 0 && $this->declinesSinceLastApproval($ctx, $conn, $t['transactions'], $c, $exclude) >= $max) {
            return 'max_declines';
        }

        return null;
    }

    /**
     * Merge the published config over the defaults. Assoc maps (tables, columns,
     * product_udfs) are shallow-replaced so a partial override works; list values
     * (code lists, geo) are taken wholesale from config when present. NOT
     * array_replace_recursive — that would merge lists by index, so overriding
     * product_udfs.cc => ['PDF'] would wrongly yield ['PDF','CCC','CCR'].
     */
    private function config(): array
    {
        $d = self::DEFAULTS;
        $o = (array) config('billing-engine.negative_db', []);

        return [
            'connection'         => $o['connection'] ?? $d['connection'],
            'tables'             => array_replace($d['tables'], $o['tables'] ?? []),
            'columns'            => array_replace($d['columns'], $o['columns'] ?? []),
            'hard_decline'       => $o['hard_decline'] ?? $d['hard_decline'],
            'hard_decline_stack' => $o['hard_decline_stack'] ?? $d['hard_decline_stack'],
            'max_declines'       => $o['max_declines'] ?? $d['max_declines'],
            'decline_count_exclude' => $o['decline_count_exclude'] ?? $d['decline_count_exclude'],
            'product_udfs'       => array_replace($d['product_udfs'], $o['product_udfs'] ?? []),
            'blacklisted_geo'    => $o['blacklisted_geo'] ?? $d['blacklisted_geo'],
        ];
    }

    /** The member's card BIN — from the seeded meta, else the latest ledger row. */
    private function bin(BillingContext $ctx, string $conn, string $txTable, array $c): ?string
    {
        $bin = $ctx->row->meta['bin'] ?? null;
        if ($bin !== null && $bin !== '') {
            return (string) $bin;
        }

        return DB::connection($conn)->table($txTable)
            ->where($c['member'], $ctx->memberId())
            ->orderByDesc($c['date'])
            ->value($c['ledger_bin']);
    }

    /** Count declines (amount > 2) after the member's most recent approval. */
    private function declinesSinceLastApproval(BillingContext $ctx, string $conn, string $txTable, array $c, array $exclude = []): int
    {
        $lastApproved = DB::connection($conn)->table($txTable)
            ->where($c['member'], $ctx->memberId())
            ->where($c['resp'], 0)
            ->orderByDesc($c['date'])
            ->value($c['date']);

        $q = DB::connection($conn)->table($txTable)
            ->where($c['member'], $ctx->memberId())
            ->where($c['resp'], '!=', 0)
            ->where($c['amount'], '>', 2);

        if ($exclude) {
            $q->whereNotIn($c['resp'], $exclude);
        }

        if ($lastApproved) {
            $q->where($c['date'], '>', $lastApproved);
        }

        return $q->count();
    }
}
