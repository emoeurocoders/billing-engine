<?php

/**
 * EXAMPLE — the sports stored-card ("AZ") vault. Faithful port of
 * NMIBilling::getAzKey() plus the exp/billing the legacy AZ block read off the
 * ledger.
 *
 * Note what is NOT here: the token TABLE. That's config
 * (`billing-engine.az.tokens.table` → 'sports_tokens'), read by the base class.
 * So a different vertical reuses this pattern by changing config + its own
 * decrypt() — it does NOT hardcode a model/table. The only app-specific code is
 * decrypt() (Vault + Crypt) and where exp/billing come from.
 *
 * Place at App\Billing\SportsCardVault and bind it:
 *   $this->app->singleton(\Omni\BillingEngine\Contracts\CardVault::class, \App\Billing\SportsCardVault::class);
 */

namespace App\Billing;

use App\Library\Vault;
use App\Models\OmniTransaction;
use Illuminate\Support\Facades\Crypt;
use Omni\BillingEngine\Cards\AbstractTokenCardVault;
use Omni\BillingEngine\Support\BillingContext;

class SportsCardVault extends AbstractTokenCardVault
{
    /**
     * $tokenRow is the row from the configured `az.tokens.table` (sports_tokens);
     * its `token` column is the Crypt key — the legacy getAzKey() scheme.
     *
     * @return array{number:string,cvv:string}|null
     */
    protected function decrypt(object $tokenRow, BillingContext $ctx): ?array
    {
        $vault = Vault::get($ctx->memberId());
        if (!$vault || $vault['data'] == null) {
            return null;
        }

        $decrypted = Crypt::decryptString($vault['data']['data']['value'], false, $tokenRow->token);
        [$number, $az] = array_pad(explode(':', $decrypted), 2, null);

        return ($number && $az) ? ['number' => $number, 'cvv' => $az] : null;
    }

    /** Exp: seeded meta, else the member's latest approved ledger row (legacy read). */
    protected function cardExp(BillingContext $ctx): ?string
    {
        if (!empty($ctx->row->meta['card_exp'])) {
            return $ctx->row->meta['card_exp'];
        }

        $tx = $this->ledgerRow($ctx);

        return $tx ? str_pad((string) $tx->expiremonth, 2, '0', STR_PAD_LEFT) . $tx->expireyear : null;
    }

    /** Billing: seeded meta, else the ledger row (legacy setBilling() fields). */
    protected function billing(BillingContext $ctx): array
    {
        if (!empty($ctx->row->meta['billing'])) {
            return $ctx->row->meta['billing'];
        }

        $tx = $this->ledgerRow($ctx);
        if (!$tx) {
            return ['uid' => $ctx->memberId()];
        }

        return array_filter([
            'uid'        => $ctx->memberId(),
            'username'   => $tx->username,
            'email'      => $tx->email,
            'first_name' => $tx->fname,
            'last_name'  => $tx->lname,
            'zip'        => $tx->tui_bill_zip,
            'country'    => $tx->tui_bill_country,
            'ip'         => $tx->tui_ip,
            'device'     => $tx->affiliate,
        ], fn ($v) => $v !== null);
    }

    private ?object $ledger = null;

    private function ledgerRow(BillingContext $ctx): ?object
    {
        return $this->ledger ??= OmniTransaction::where('cust_id_ext', $ctx->memberId())
            ->where('resp_id', 0)
            ->orderByDesc('tr_date')
            ->first();
    }
}
