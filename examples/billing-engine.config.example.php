<?php

/**
 * EXAMPLE — a filled-in config for two real verticals. Copy the relevant block
 * into the app's published config/billing-engine.php.
 *
 * Everything that differs between verticals is here: gateway, MID table, amounts,
 * UDFs, cycle, guard chain, stepdown ladders. No package code changes.
 */

return [

    // ---- SPORTS (NMI, mids table) -----------------------------------------
    'sports' => [
        'vertical' => 'sports',
        'gateway'  => ['driver' => 'nmi'],
        'mids'     => ['connection' => null, 'table' => 'mids'],
        'schedule' => ['connection' => null, 'table' => 'billing_schedule_sports'],
        // NegativeDb data source — sports defaults shown; override per vertical.
        'negative_db' => [
            'connection' => 'omnistats',
            'tables' => [
                'credits'      => 'auth_credits_sports',
                'chargebacks'  => 'auth_chargebacks_sports',
                'cancels'      => 'cancels_sports',
                'bin_block'    => 'sports_bin_block',
                'blocked'      => 'blocked_members',
                'transactions' => 'auth_transactions_sports',
            ],
            'hard_decline'       => ['106', '107', '111', '112', '123', '164', '165', '201', '264', '460'],
            'hard_decline_stack' => ['111', '159'],
            'max_declines'       => 3,
            'product_udfs'       => ['cc' => ['CC', 'CCC', 'CCR'], 'pp' => ['PP', 'PPC', 'PPR']],
            'blacklisted_geo'    => [],
        ],
        'cycle_days' => 30,
        'types' => [
            'rebill' => [
                'handler' => \Omni\BillingEngine\Handlers\RebillHandler::class,
                'selection' => 'sticky', 'udf2' => 'CCR', 'eligible_udf2' => ['CCC', 'CCR'],
                'guards' => ['already_attempted', 'mid_cap', 'negative_db', 'max_declines', 'same_day', 'conversion_rebill'],
                'log_table' => 'rebill_sports', 'max_declines' => 3,
            ],
            'cross1' => [
                'handler' => \Omni\BillingEngine\Handlers\CrossHandler::class,
                'selection' => 'sticky', 'eligible_udf2' => ['CCPGC', 'CCPGR', 'PPPGC', 'PPPGR'],
                'guards' => ['already_attempted', 'mid_cap', 'negative_db'],
                'log_table' => 'rebill_games_sports',
                'stepdowns' => [
                    'max_steps' => 1, 'on_exhausted' => 'defer',
                    'rules' => [['step' => 0, 'card_type' => 'any', 'codes' => ['105', '109', '157'], 'after_days' => 10, 'to' => 19.90]],
                ],
            ],
            'cross2' => [
                'handler' => \Omni\BillingEngine\Handlers\CrossHandler::class,
                'selection' => 'sticky', 'eligible_udf2' => ['CCPMC', 'CCPMR', 'PPPMC', 'PPPMR'],
                'guards' => ['already_attempted', 'mid_cap', 'negative_db'],
                'log_table' => 'rebill_vod_sports',
                'stepdowns' => [
                    'max_steps' => 1, 'on_exhausted' => 'defer',
                    'rules' => [['step' => 0, 'card_type' => 'any', 'codes' => ['104', '105', '109', '157'], 'after_days' => 10, 'to' => 19.79]],
                ],
            ],
            'settle' => [
                'handler' => \Omni\BillingEngine\Handlers\SettleHandler::class,
                'selection' => 'sticky', 'settle_after_days' => 2,
                'guards' => ['already_attempted'], 'log_table' => 'settle_sports',
            ],
        ],
    ],

    // ---- PDF (Inovio, pdf_mids table) -------------------------------------
    'pdf' => [
        'vertical' => 'pdf',
        'gateway'  => ['driver' => 'inovio'],
        'mids'     => ['connection' => null, 'table' => 'pdf_mids'],
        'schedule' => ['connection' => null, 'table' => 'billing_schedule_pdf'],
        // Same guard, different tables — this is why negative_db is all config.
        'negative_db' => [
            'connection' => 'omnistats',
            'tables' => [
                'credits'      => 'auth_credits_pdf',
                'chargebacks'  => 'auth_chargebacks_pdf',
                'cancels'      => 'cancels_pdf',
                'bin_block'    => 'pdf_bin_block',
                'blocked'      => 'blocked_members',
                'transactions' => 'auth_transactions_pdf',
            ],
            'product_udfs' => ['cc' => ['PDF'], 'pp' => ['PDF']],
        ],
        'cycle_days' => 30,
        'types' => [
            'rebill' => [
                'handler' => \Omni\BillingEngine\Handlers\RebillHandler::class,
                'selection' => 'sticky', 'udf2' => 'PDF', 'eligible_udf2' => ['PDF'],
                'guards' => ['already_attempted', 'mid_cap', 'negative_db', 'max_declines'],
                'log_table' => 'rebill_pdf', 'max_declines' => 3,
            ],
            // manuals vertical is identical but mids.table => 'manuals_mids'
        ],
    ],
];
