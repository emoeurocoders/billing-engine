<?php

namespace Omni\BillingEngine\Guards;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Faithful port of the legacy NMIBilling::conversionRebill(): SKIP a member who
 * already has an APPROVED transaction (resp_id=0) in this product's UDF set
 * within the current cycle window. This reads the LEDGER — a different source
 * from the `already_attempted` guard (which reads the rebill_* attempt log) — so
 * it catches members already billed this cycle via another path (a conversion,
 * a manual charge) that never hit the rebill log.
 *
 * Returns SKIP (defer to next cycle), matching the legacy `continue`.
 *
 * Every table/column/UDF-set is config (`billing-engine.conversion_rebill`) because
 * these differ per vertical/app; the sports tables are the built-in defaults. An
 * app may bind `billing.conversionRebill` to replace the built-in check entirely
 * (closure receives the BillingContext, returns bool = already billed this cycle).
 */
class ConversionRebill implements BillingGuard
{
    /** Fallback defaults (sports). Config overrides these key-by-key. */
    private const DEFAULTS = [
        'connection' => 'omnistats',
        'table'      => 'auth_transactions_sports',
        'columns' => [
            'member' => 'cust_id_ext',
            'resp'   => 'resp_id',
            'udf'    => 'tui_udf02',
            'date'   => 'tr_date_only',   // date-only column used for the window
        ],
        'product_udfs' => [
            'cc' => ['CC', 'CCC', 'CCR'],
            'pp' => ['PP', 'PPC', 'PPR'],
        ],
        // Window = last (cycle_days - 1) days, in the billing timezone. Null =
        // fall back to the global billing-engine.cycle_days.
        'cycle_days' => null,
    ];

    public function key(): string { return 'conversion_rebill'; }

    public function check(BillingContext $ctx): GuardResult
    {
        // Escape hatch: an app-bound closure fully replaces the built-in check.
        if (app()->bound('billing.conversionRebill')) {
            return app('billing.conversionRebill')($ctx)
                ? GuardResult::skip('conversion_rebill')
                : GuardResult::pass();
        }

        return $this->billedThisCycle($ctx)
            ? GuardResult::skip('conversion_rebill')
            : GuardResult::pass();
    }

    private function billedThisCycle(BillingContext $ctx): bool
    {
        $cfg  = $this->config();
        $udfs = $cfg['product_udfs'][$ctx->cardType()] ?? [];

        // No UDF set for this card type → nothing to scope the check to; pass.
        if (!$udfs) {
            return false;
        }

        $c    = $cfg['columns'];
        $tz   = config('billing-engine.timezone', 'MST7MDT');
        $days = (int) ($cfg['cycle_days'] ?? config('billing-engine.cycle_days', 30));

        $from = Carbon::now($tz)->subDays($days - 1)->toDateString();
        $to   = Carbon::now($tz)->toDateString();

        return DB::connection($cfg['connection'])
            ->table($cfg['table'])
            ->where($c['member'], $ctx->memberId())
            ->where($c['resp'], 0)
            ->whereIn($c['udf'], $udfs)
            ->whereBetween($c['date'], [$from, $to])
            ->exists();
    }

    /**
     * Merge published config over defaults. Assoc maps (columns, product_udfs)
     * shallow-replace so partial overrides work; NOT array_replace_recursive
     * (which would merge lists by index).
     */
    private function config(): array
    {
        $d = self::DEFAULTS;
        $o = (array) config('billing-engine.conversion_rebill', []);

        return [
            'connection'   => $o['connection'] ?? $d['connection'],
            'table'        => $o['table'] ?? $d['table'],
            'columns'      => array_replace($d['columns'], $o['columns'] ?? []),
            'product_udfs' => array_replace($d['product_udfs'], $o['product_udfs'] ?? []),
            'cycle_days'   => $o['cycle_days'] ?? $d['cycle_days'],
        ];
    }
}
