<?php

use Balerka\LaravelPayhub\Http\Controllers\CardsController;
use Balerka\LaravelPayhub\Http\Controllers\TestPaymentsController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('payhub.route_prefix'))
    ->middleware(config('payhub.route_middleware'))
    ->group(function (): void {
        if (config('payhub.frontend') === 'react') {
            Route::get('cards', [CardsController::class, 'index'])->name('payhub.cards');
        }

        Route::put('cards/default', [CardsController::class, 'setDefault'])->name('payhub.cards.default');
        Route::delete('cards/{card}', [CardsController::class, 'destroy'])->name('payhub.cards.destroy');
    });

Route::prefix(config('payhub.route_prefix'))
    ->middleware(config('payhub.api_middleware'))
    ->group(function (): void {
        Route::get('cards/data', [CardsController::class, 'data'])->name('payhub.cards.data');

        if (config('payhub.test_mode')) {
            Route::post('payments/test/pay', [TestPaymentsController::class, 'store'])->name('payhub.test.pay');
        }
    });
