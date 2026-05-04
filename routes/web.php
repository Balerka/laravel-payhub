<?php

use Balerka\LaravelReactPayments\Http\Controllers\CardsController;
use Balerka\LaravelReactPayments\Http\Controllers\TestPaymentsController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('payments.route_prefix'))
    ->middleware(config('payments.route_middleware'))
    ->group(function (): void {
        Route::get('cards', [CardsController::class, 'index'])->name('payments.cards');
        Route::put('cards/default', [CardsController::class, 'setDefault'])->name('payments.cards.default');
        Route::delete('cards/{card}', [CardsController::class, 'destroy'])->name('payments.cards.destroy');
    });

Route::prefix(config('payments.route_prefix'))
    ->middleware(config('payments.api_middleware'))
    ->group(function (): void {
        Route::get('cards/data', [CardsController::class, 'data'])->name('payments.cards.data');

        if (config('payments.test_mode')) {
            Route::post('payments/test/pay', [TestPaymentsController::class, 'store'])->name('payments.test.pay');
        }
    });
