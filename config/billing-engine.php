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

    /*
    |--------------------------------------------------------------------------
    | Non-charge outcome log (skips + kills)
    |--------------------------------------------------------------------------
    | billing_events_{vertical} — one row per SKIP or DEAD, the outcomes a guard
    | produces BEFORE any charge, so the attempt log never sees them. Written by
    | BillingEventLogger as an event subscriber; nothing in the billing path or
    | the existing tables changes. Turn `enabled` off and the engine behaves
    | exactly as before (the dashboard then falls back to deriving today's skips
    | from claim timestamps, and shows nothing for past days).
    |
    | Run `php artisan migrate` after enabling — writes are guarded, so a missing
    | table costs you the data, not the charge.
    */
    'events' => [
        'enabled'    => env('BILLING_EVENTS_ENABLED', true),
        'connection' => env('BILLING_EVENTS_CONNECTION', null), // null = default
        'table'      => env('BILLING_EVENTS_TABLE', 'billing_events_' . env('BILLING_VERTICAL', 'sports')),
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
            'tickets'      => 'auth_tickets_sports',       // rebillSettles source (a rebill)
            'attempts'     => 'rebill_sports',             // already-billed-this-cycle reconcile
            'auths'        => 'auth_only_sports',          // settle (capture) + convert enrolment
        ],
        // tui_udf02 sets that map to each card type (source filter).
        'udf2' => [
            'cc' => ['CCC', 'CCR'],
            'pp' => ['PPC', 'PPR'],
        ],
        // rebillSettles amounts on the tickets table.
        'settle_amounts' => [34.55, 29.55, 19.55],

        // One-shot enrolment timing (daily seeds). Due = auth_date + N days.
        'settle_after_days' => 2,      // capture a full auth N days later (settleAuths)
        'convert_days'      => 5,      // convert a $0 auth N days later (convertInitials)
        'convert_amount'    => 29.55,  // conversion charge amount (cc_convert_amount)
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
        // Codes excluded from that count: merchant-side failures (407 = Invalid
        // merchant ID) are the MID's fault, not the cardholder's — counting them
        // would push paying members into the negative db whenever a MID breaks.
        'decline_count_exclude' => ['407'],
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
    | Conversion / rebill gate
    |--------------------------------------------------------------------------
    | The ConversionRebill guard's data source (legacy conversionRebill()). SKIP a
    | member who already has an APPROVED ledger transaction in this product's UDF
    | set within the current cycle window. Per-vertical tables, so it's config;
    | sports defaults shown. `cycle_days` null → the global cycle_days. An app may
    | bind `billing.conversionRebill` to replace the built-in check.
    */
    'conversion_rebill' => [
        'connection' => env('BILLING_CONVREB_CONNECTION', 'omnistats'),
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
        'cycle_days' => null,             // null → global billing-engine.cycle_days
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

        // Decline-code normalisation OVERRIDES, per driver, merged over the
        // adapter's built-in map. The complete legacy NMIBilling::$response_codes
        // ships inside NmiGateway, so this is only for additions/changes without
        // touching the package (e.g. a new processor code). Example:
        //   'code_maps' => ['nmi' => ['470' => '104'], 'inovio' => ['610' => '105']],
        'code_maps' => [
            'nmi'    => [],
            'inovio' => [],
        ],
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

        // Self-healing: at the top of each dispatch, any row still 'claimed' this
        // long is an ORPHAN (its job vanished before completing — worker restart,
        // lost enqueue, etc.) and is returned to 'pending' so it bills next tick.
        // MUST exceed the worst-case job lifetime so a legitimately-retrying job is
        // never reclaimed underneath the worker: tries * timeout + sum(backoff).
        // With tries=3 + backoff [60,300,900] that's ~21m, so keep this >= 30.
        'reclaim_after_minutes' => 30,
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

    // When a row passes every guard but no usable MID resolves (all candidates
    // closed / no live redirect yet), it is a TRANSIENT miss — retry soon rather
    // than waiting a full cycle, because redirects are often added daily. This is
    // the retry interval for that no-MID case only; real guard skips still defer a
    // full cycle_days. Per-type overridable via types.{type}.no_mid_retry_days.
    'no_mid_retry_days' => 1,

    'types' => [

        'rebill' => [
            'handler'      => \Omni\BillingEngine\Handlers\RebillHandler::class,
            'selection'    => 'sticky',           // sticky | rotation
            'udf2'         => ['cc' => 'CCR', 'pp' => 'PPR'], // per-card: legacy rebillCC=CCR, rebillPP=PPR
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

    /*
    |--------------------------------------------------------------------------
    | Logging (per-action audit trail)
    |--------------------------------------------------------------------------
    | When enabled, BillingLogSubscriber writes a line for EVERY action
    | (ATTEMPTING/SUCCESS/DECLINE/SKIP/DEAD/STEPDOWN) to `channel` — the
    | replacement for the legacy log() file. Point `channel` at a DEDICATED log
    | channel (define one in config/logging.php) so billing gets its own rotating
    | file, e.g.:
    |
    |   // config/logging.php → 'channels'
    |   'billing' => [
    |       'driver' => 'daily',
    |       'path'   => storage_path('logs/billing/billing.log'),
    |       'days'   => 90,
    |       'level'  => 'debug',   // 'debug' includes ATTEMPTING; 'info' = outcomes only
    |   ],
    |
    | then set BILLING_LOG_CHANNEL=billing. Every charge is ALSO recorded in the
    | DB attempt log (billing_attempts_{vertical} + legacy tables) regardless.
    */
    'logging' => [
        'enabled' => env('BILLING_LOG_ENABLED', true),
        'channel' => env('BILLING_LOG_CHANNEL', null), // null = the app's default channel

        // Permission applied to the channel's log FILE after it is opened, so a
        // non-root worker can append outcome lines to a file the root cron
        // dispatcher created (otherwise those lines are silently dropped). Octal.
        'file_permission' => env('BILLING_LOG_FILE_PERMISSION', 0777),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rebill status dashboard (web)
    |--------------------------------------------------------------------------
    | A read-only day-by-day view of the rebill schedule: how many are due,
    | processed (billed / declined / killed), still pending, and the backlog.
    | Routes load ONLY when `enabled` is true. Same access pattern as the
    | mid-balancer dashboard: an ?key= access key OR an allowed-IP list.
    */
    'dashboard' => [
        'enabled'     => env('BILLING_DASHBOARD_ENABLED', false),
        'prefix'      => env('BILLING_DASHBOARD_PREFIX', 'billing'),
        'middleware'  => ['web'],
        'access_key'  => env('BILLING_DASHBOARD_KEY', null),
        'allowed_ips' => env('BILLING_DASHBOARD_IPS', '127.0.0.1,::1'),
    ],
];
