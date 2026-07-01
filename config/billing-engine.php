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
