<?php

namespace Omni\BillingEngine\Cards;

use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\CardVault;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\CardData;

/**
 * Base CardVault that does the config-driven token-table read for you, so a
 * vertical only implements the app-specific decrypt.
 *
 * The token table name/connection/column differ per vertical/app, so they come
 * from `billing-engine.az.tokens` config — NOT a hardcoded model. This base:
 *
 *   1. reads the member's token row from the configured table,
 *   2. hands it to decrypt() (the only app-specific part), and
 *   3. assembles CardData (exp/billing default to the seeded row meta; override
 *      cardExp()/billing() to source them elsewhere, e.g. the ledger).
 *
 * A subclass is typically a few lines — see examples/SportsCardVault.php.
 */
abstract class AbstractTokenCardVault implements CardVault
{
    public function resolve(BillingContext $ctx): ?CardData
    {
        $cfg   = (array) config('billing-engine.az.tokens', []);
        $table = $cfg['table'] ?? null;
        if (!$table) {
            return null; // no token table configured → token rebill path
        }

        $memberCol = $cfg['columns']['member'] ?? 'user_id';

        $tokenRow = DB::connection($cfg['connection'] ?? null)
            ->table($table)
            ->where($memberCol, $ctx->memberId())
            ->first();

        if (!$tokenRow) {
            return null; // no stored card for this member
        }

        $card = $this->decrypt($tokenRow, $ctx);
        if (!$card || empty($card['number']) || empty($card['cvv'])) {
            return null;
        }

        return new CardData(
            cardNumber: (string) $card['number'],
            cvv: (string) $card['cvv'],
            cardExp: $card['exp'] ?? $this->cardExp($ctx),
            billing: $card['billing'] ?? $this->billing($ctx),
        );
    }

    /**
     * Decrypt the token row into card data. The ONLY app-specific part.
     *
     * @param object $tokenRow the row read from the configured token table
     * @return array{number:string,cvv:string,exp?:?string,billing?:array}|null
     */
    abstract protected function decrypt(object $tokenRow, BillingContext $ctx): ?array;

    /** MMYY expiry — defaults to the seeded row meta; override to source elsewhere. */
    protected function cardExp(BillingContext $ctx): ?string
    {
        return $ctx->row->meta['card_exp'] ?? null;
    }

    /** setBilling() fields — defaults to the seeded row meta; override to source elsewhere. */
    protected function billing(BillingContext $ctx): array
    {
        return $ctx->row->meta['billing'] ?? ['uid' => $ctx->memberId()];
    }
}
