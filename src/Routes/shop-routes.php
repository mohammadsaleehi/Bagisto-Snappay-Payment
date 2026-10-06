<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Webkul\SnappPay\Http\Controllers\SnappPayController;

Route::middleware(['web'])->group(function () {
    Route::post('snapp-pay/callback', [SnappPayController::class, 'callback'])
        ->name('snapp_pay.callback')
        ->withoutMiddleware([ValidateCsrfToken::class]);
});
