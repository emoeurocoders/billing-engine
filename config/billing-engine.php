<?php

/*
|--------------------------------------------------------------------------
| billing-engine configuration
|--------------------------------------------------------------------------
|
| Publish this file into each vertical app and tune it. Most per-vertical
| variance (gateway, MID source table, amounts, UDFs, cycle, decline maps,
| stepdown ladders) is expressed HERE — no package code changes needed.
|
| See examples/billing-engine.config.example.php for a filled-in vertical.
*/

return [

    // The vertical/stack this app bills for. Drives the schedule table name
    // (billing_schedule_{vertical}) and the `stack` written to MID state.
    'vertical' => env('BILLING_VERTICAL', 'sports'),

    /*
    |--------------------------------------------------------------------------
    | Schedule table
    |--------------------------------------------------------------------------
    | One table per vertical (millions of rows × 6). Name is resolved at
    | runtime so the same migration/code serves every vertical.
    */
    'schedule' => [
        'connection' => env('BILLING_SCHEDULE_CONNECTION', null), // null = default
        'table'      => env('BILLING_SCHEDULE_TABLE', 'billing_schedule_' . env('BILLING_VERTICAL', 'sports')),
    ],

    /*
    |--------------------------------------------------------------------------
    | MID source (config registry)
    |--------------------------------------------------------------------------
    | Verticals read MID config from different tables, all same-shape and all
    | on the bigentertainment (default) connection:
    |   sports → 'mids', pdf → 'pdf_mids', manuals → 'manuals_mids', ...
    */
    'mids' => [
        'connection' => env('BILLING_MIDS_CONNECTION', null), // null = default (bigentertainment)
        'table'      => env('BILLING_MIDS_TABLE', 'mids'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attempt log
    |--------------------------------------------------------------------------
    | The engine-owned unified attempt table (billing_attempts_{vertical}) holds
    | every billing_type. During migration we DUAL-WRITE: this table + the legacy
    | per-type tables (rebill_sports, rebill_games_sports, ...). Reads come from
    | `read_from` — keep it on 'legacy' (has history) until the unified table has
    | a full cycle, then flip to 'unified' and drop the legacy write/tables.
    */
    'attempts' => [
        'connection' => env('BILLING_ATTEMPTS_CONNECTION', null), // null = default
        'table'      => env('BILLING_ATTEMPTS_TABLE', 'billing_attempts_' . env('BILLING_VERTICAL', 'sports')),
    ],

    'log' => [
        'dual_write' => env('BILLING_LOG_DUAL_WRITE', true), // write unified + legacy
        'legacy'     => env('BILLING_LOG_LEGACY', true),     // include legacy tables as a write target
        'connection' => env('BILLING_LOG_CONNECTION', 'omnistats'), // legacy tables live here
        'read_from'  => env('BILLING_LOG_READ_FROM', 'legacy'),     // legacy | unified
    ],

    /*
    |--------------------------------------------------------------------------
    | Backfill / seed source
    |--------------------------------------------------------------------------
    | Table names the app's seed generator (billing.seedSource) reads when
    | materialising the schedule from the legacy ledger. These differ per
    | vertical/app, so they live here — change them and the same seed generator
    | works for another app. (The package's SeedScheduleCommand never reads these;
    | only the app generator does — see examples/SeedSourceProvider.php.)
    */
    'seed' => [
        'connection' => env('BILLING_SEED_CONNECTION', 'omnistats'),
        'sources' => [
            'transactions' => 'auth_transactions_sports', // rebillCC / rebillPP ledger
            'tickets'      => 'auth_tickets_sports',       // rebillSettles source
            'attempts'     => 'rebill_sports',             // already-billed-this-cycle reconcile
        ],
        // tui_udf02 sets that map to each card type (source filter).
        'udf2' => [
            'cc' => ['CCC', 'CCR'],
            'pp' => ['PPC', 'PPR'],
        ],
        // rebillSettles amounts on the tickets table.
        'settle_amounts' => [34.55, 29.55, 19.55],
    ],

    /*
    |--------------------------------------------------------------------------
    | Negative database (do-not-bill gate)
    |--------------------------------------------------------------------------
    | The NegativeDb guard's data source. Every table/column/code list lives
    | here because these differ per vertical/app (sports → auth_credits_sports /
    | sports_bin_block, pdf → its own equivalents, ...). Override the whole block
    | in each vertical's published config. Leave `connection` null to use the
    | default. An app may instead bind `billing.negativeDb` to replace the checks.
    */
    'negative_db' => [
        'connection' => env('BILLING_NEGDB_CONNECTION', 'omnistats'),

        // Per-vertical table names.
        'tables' => [
            'credits'      => 'auth_credits_sports',
            'chargebacks'  => 'auth_chargebacks_sports',
            'cancels'      => 'cancels_sports',
            'bin_block'    => 'sports_bin_block',
            'blocked'      => 'blocked_members',
            'transactions' => 'auth_transactions_sports',
        ],

        // Per-vertical column names (only override the ones that differ).
        'columns' => [
            'member'        => 'cust_id_ext',   // member id on credits/transactions
            'cb_member'     => 'customerid',     // member id on chargebacks
            'cancel_member' => 'member_id',      // member id on cancels
            'block_member'  => 'customer_id',    // member id on blocked table
            'bin'           => 'bin',            // bin column on the bin_block table
            'resp'          => 'resp_id',        // response/decline code on transactions
            'udf'           => 'tui_udf02',      // product udf on credits/transactions
            'amount'        => 'tr_amount',      // amount on credits/transactions
            'date'          => 'tr_date',        // ordering column on transactions
            'ledger_bin'    => 'bankbin',        // bin column on the transactions ledger
            'credit_type'   => 'ttype_name',     // transaction-type column on credits
        ],

        // Decline codes that permanently stop a member (canonical resp_id values).
        'hard_decline'       => ['106', '107', '111', '112', '123', '164', '165', '201', '264', '460'],
        // Stack-wide hard declines checked across ALL products.
        'hard_decline_stack' => ['111', '159'],
        // Declines since the last approval that trigger a permanent stop.
        'max_declines'       => 3,
        // card_type → the product UDF set used to scope the credit / hard-decline checks.
        'product_udfs'       => [
            'cc' => ['CC', 'CCC', 'CCR'],
            'pp' => ['PP', 'PPC', 'PPR'],
        ],
        // Countries blocked for conversions (legacy noStepDownGeo / blackListedGeo).
        'blacklisted_geo'    => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    | 'nmi' (3 verticals) or 'inovio' (3 verticals). The bound GatewayClient
    | adapter translates the canonical payload to gateway-specific keys and
    | normalises the response to a GatewayResult.
    */
    'gateway' => [
        'driver' => env('BILLING_GATEWAY', 'nmi'), // nmi|inovio
    ],

    /*
    |--------------------------------------------------------------------------
    | Stored-card ("AZ") charge path
    |--------------------------------------------------------------------------
    | When a bound CardVault resolves a stored card for a member, the handler
    | charges it via the gateway card path (doCharge) instead of the token
    | rebill (doRebill) — the legacy getAzKey() flow. Turn `enabled` off to force
    | token rebills everywhere (or simply don't bind a CardVault). `order_desc`
    | null → the uppercased vertical name.
    */
    'az' => [
        'enabled'    => env('BILLING_AZ_ENABLED', true),
        'udf_3'      => env('BILLING_AZ_UDF3', 'AZ'),
        'order_desc' => env('BILLING_AZ_ORDER_DESC', null),

        // The stored-card token table — DIFFERENT per vertical/app (sports →
        // sports_tokens, pdf → its own). AbstractTokenCardVault reads the token
        // row from here; the app subclass only implements decrypt(). Override the
        // table/connection/column per vertical; the decrypt scheme stays app code.
        'tokens' => [
            'connection' => env('BILLING_AZ_TOKENS_CONNECTION', 'omnistats'),
            'table'      => env('BILLING_AZ_TOKENS_TABLE', 'sports_tokens'),
            'columns'    => [
                'member' => 'user_id', // token-row column holding the member id
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('BILLING_QUEUE_CONNECTION', null), // null = default
        'name'       => env('BILLING_QUEUE', 'billing'),
        'tries'      => 3,
        'backoff'    => [60, 300, 900], // seconds between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatcher
    |--------------------------------------------------------------------------
    | How many due rows to claim per dispatch tick, and the claim lock window.
    */
    'dispatch' => [
        'claim_batch'    => 500,
        'lock_seconds'   => 50,   // < the dispatch interval; auto-released
        'per_mid_throttle' => null, // e.g. ['max' => 1, 'per_seconds' => 1]
    ],

    'timezone' => env('BILLING_TZ', 'MST7MDT'),

    /*
    |--------------------------------------------------------------------------
    | Per billing_type settings
    |--------------------------------------------------------------------------
    | The discriminator that unifies rebillCC/PP/Settles/Cross1/Cross2 and
    | absorbs conversions + settlements. Each type maps to a handler and its
    | own data (UDFs, amount, cycle, selection mode, guard chain).
    */
    'cycle_days' => 30,

    'types' => [

        'rebill' => [
            'handler'      => \Omni\BillingEngine\Handlers\RebillHandler::class,
            'selection'    => 'sticky',           // sticky | rotation
            'udf2'         => 'CCR',
            'eligible_udf2'=> ['CCC', 'CCR'],      // source filter
            'cycle_days'   => 30,
            'guards'       => ['already_attempted', 'mid_cap', 'negative_db', 'max_declines', 'same_day', 'conversion_rebill'],
            'log_table'    => 'rebill_sports',     // legacy attempt log (per vertical)

            // Step-down ladder (replaces rebillStepDownCC + rebillStepDownMatching).
            // step 0 = first decline at full price; step 1 = matching-MID retry.
            'stepdowns' => [
                'max_steps'        => 2,
                'on_exhausted'     => 'defer',     // declined rebill just waits next cycle
                'country_excluded' => ['JM', 'PG', 'RE', 'BS'],
                'rules' => [
                    ['step' => 0, 'card_type' => 'cc', 'codes' => ['104', '105', '109', '157'], 'after_days' => 8, 'to' => 19.55, 'mid' => 'sticky'],
                    ['step' => 0, 'card_type' => 'cc', 'codes' => ['154', '407'],               'after_days' => 3, 'to' => null,  'mid' => 'sticky'],
                    ['step' => 0, 'card_type' => 'pp', 'codes' => ['105'],                       'after_days' => 8, 'to' => 19.55, 'mid' => 'sticky'],
                    // step 1 — "matching" MID (same descriptor, different MID)
                    ['step' => 1, 'card_type' => 'cc', 'codes' => ['105', '109', '157'], 'after_days' => 3, 'to' => 19.55, 'mid' => 'match'],
                    ['step' => 1, 'card_type' => 'cc', 'codes' => ['154', '407'],        'after_days' => 3, 'to' => null,  'mid' => 'match'],
                ],
            ],
        ],

        'cross1' => [
            'handler'      => \Omni\BillingEngine\Handlers\CrossHandler::class,
            'selection'    => 'sticky',
            'udf2'         => 'CCR',
            'eligible_udf2'=> ['CCPGC', 'CCPGR', 'PPPGC', 'PPPGR'],
            'cycle_days'   => 30,
            'guards'       => ['already_attempted', 'mid_cap', 'negative_db', 'max_declines'],
            'log_table'    => 'rebill_games_sports',
            'stepdowns' => [                       // rebillStepDownCross1
                'max_steps'    => 1,
                'on_exhausted' => 'defer',
                'rules' => [
                    ['step' => 0, 'card_type' => 'any', 'codes' => ['105', '109', '157'], 'after_days' => 10, 'to' => 19.90, 'mid' => 'sticky'],
                ],
            ],
        ],

        'cross2' => [
            'handler'      => \Omni\BillingEngine\Handlers\CrossHandler::class,
            'selection'    => 'sticky',
            'udf2'         => 'CCR',
            'eligible_udf2'=> ['CCPMC', 'CCPMR', 'PPPMC', 'PPPMR'],
            'cycle_days'   => 30,
            'guards'       => ['already_attempted', 'mid_cap', 'negative_db', 'max_declines'],
            'log_table'    => 'rebill_vod_sports',
            'stepdowns' => [                       // rebillStepDownCross2 (sports amounts)
                'max_steps'    => 1,
                'on_exhausted' => 'defer',
                'rules' => [
                    ['step' => 0, 'card_type' => 'any', 'codes' => ['104', '105', '109', '157'], 'after_days' => 10, 'to' => 19.79, 'mid' => 'sticky'],
                ],
            ],
        ],

        'convert' => [
            'handler'      => \Omni\BillingEngine\Handlers\ConvertHandler::class,
            'selection'    => 'rotation',         // uses MidResolver::resolveRotationMid
            'cycle_days'   => 30,
            'guards'       => ['already_attempted', 'mid_cap', 'negative_db'],
            'log_table'    => 'conversion_sports',
            'stepdowns' => [                       // stepDownCC (conversion retry)
                'max_steps'    => 2,
                'on_exhausted' => 'dead',
                'rules' => [
                    ['step' => 0, 'card_type' => 'cc', 'codes' => ['109', '157'], 'after_days' => 7, 'to' => 19.55, 'mid' => 'new'],
                    ['step' => 0, 'card_type' => 'cc', 'codes' => ['154', '407'], 'after_days' => 3, 'to' => 19.55, 'mid' => 'new'],
                ],
            ],
        ],

        'settle' => [
            'handler'      => \Omni\BillingEngine\Handlers\SettleHandler::class,
            'selection'    => 'sticky',
            'settle_after_days' => 2,             // capture auth N days later
            'guards'       => ['already_attempted', 'same_day'],
            'log_table'    => 'settle_sports',
        ],
    ],

    'logging' => [
        'enabled' => true,
        'channel' => env('BILLING_LOG_CHANNEL', 'stack'),
    ],
];
