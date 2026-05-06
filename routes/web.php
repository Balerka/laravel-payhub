<?php

use Balerka\LaravelPayhub\Http\Controllers\CardsController;
use Balerka\LaravelPayhub\Http\Controllers\CheckoutController;
use Balerka\LaravelPayhub\Http\Controllers\CloudPaymentsController;
use Balerka\LaravelPayhub\Http\Controllers\RefundsController;
use Balerka\LaravelPayhub\Http\Controllers\SubscriptionsController;
use Balerka\LaravelPayhub\Http\Controllers\TestPaymentsController;
use Balerka\LaravelPayhub\Http\Middleware\CloudPaymentsMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix(config('payhub.route_prefix'))
    ->middleware(config('payhub.route_middleware'))
    ->group(function (): void {
        Route::post('checkout/orders', [CheckoutController::class, 'store'])->name('payhub.checkout.orders.store');
        Route::delete('checkout/orders/{order}', [CheckoutController::class, 'destroy'])->name('payhub.checkout.orders.destroy');

        Route::put('cards/default', [CardsController::class, 'setDefault'])->name('payhub.cards.default');
        Route::delete('cards/{card}', [CardsController::class, 'destroy'])->name('payhub.cards.destroy');

        Route::post('subscriptions/cancel', [SubscriptionsController::class, 'cancel'])->name('payhub.subscriptions.cancel');
        Route::post('refunds/refund', [RefundsController::class, 'refund'])->name('payhub.refunds.refund');
    });

Route::prefix(config('payhub.route_prefix'))
    ->middleware(CloudPaymentsMiddleware::class)
    ->group(function (): void {
        Route::post('payments/cloud-payments/{action}', [CloudPaymentsController::class, 'action'])
            ->whereIn('action', ['check', 'pay', 'fail'])
            ->name('payhub.cloud-payments.action');
    });

Route::prefix(config('payhub.route_prefix'))
    ->middleware(config('payhub.api_middleware'))
    ->group(function (): void {
        Route::get('cards/data', [CardsController::class, 'data'])->name('payhub.cards.data');
        Route::get('checkout/data', [CheckoutController::class, 'data'])->name('payhub.checkout.data');
        Route::get('subscriptions/data', [SubscriptionsController::class, 'data'])->name('payhub.subscriptions.data');
        Route::get('refunds/data', [RefundsController::class, 'data'])->name('payhub.refunds.data');

        if (config('payhub.test_mode')) {
            Route::post('payments/test/pay', [TestPaymentsController::class, 'store'])->name('payhub.test.pay');
        }
    });
