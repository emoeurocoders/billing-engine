<?php

use Illuminate\Support\Facades\Route;
use Omni\BillingEngine\Http\Controllers\RebillStatusController;
use Omni\BillingEngine\Http\Middleware\RestrictByIp;

Route::prefix(config('billing-engine.dashboard.prefix', 'billing'))
    ->middleware(array_merge(
        config('billing-engine.dashboard.middleware', ['web']),
        [RestrictByIp::class]
    ))
    ->group(function () {
        Route::get('/', [RebillStatusController::class, 'index'])->name('billing-engine.rebills');
        Route::get('/api/data', [RebillStatusController::class, 'apiData'])->name('billing-engine.rebills.data');
    });
