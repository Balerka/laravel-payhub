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
    ->middleware(config('payhub.route_middleware', ['web', 'auth']))
    ->group(function (): void {
        Route::post('checkout/orders', [CheckoutController::class, 'store'])->name('payhub.checkout.orders.store');
        Route::delete('checkout/orders/{order}', [CheckoutController::class, 'destroy'])->name('payhub.checkout.orders.destroy');

        Route::put('cards/default', [CardsController::class, 'setDefault'])->name('payhub.cards.default');
        Route::delete('cards/{card}', [CardsController::class, 'destroy'])->name('payhub.cards.destroy');

        Route::post('subscriptions/cancel', [SubscriptionsController::class, 'cancel'])->name('payhub.subscriptions.cancel');

        Route::post('refunds/refund', [RefundsController::class, 'refund'])->name('payhub.refunds.refund');

        Route::get('cards/data', [CardsController::class, 'data'])->name('payhub.cards.data');
        Route::get('checkout/data', [CheckoutController::class, 'data'])->name('payhub.checkout.data');
        Route::get('subscriptions/data', [SubscriptionsController::class, 'data'])->name('payhub.subscriptions.data');
        Route::get('refunds/data', [RefundsController::class, 'data'])->name('payhub.refunds.data');

        Route::post('payments/test/pay', [TestPaymentsController::class, 'store'])->name('payhub.test.pay');
    });

Route::prefix(config('payhub.route_prefix'))
    ->middleware(config('payhub.public_route_middleware', ['web', 'throttle:10,1']))
    ->group(function (): void {
        Route::post('subscriptions/cancel-by-email', [SubscriptionsController::class, 'cancelByEmail'])->name('payhub.subscriptions.cancel-by-email');
    });

Route::prefix(config('payhub.gateways.cloud_payments.route_prefix', config('payhub.cloud_payments_route_prefix', 'api/cloudpayments')))
    ->middleware(array_merge(config('payhub.gateways.cloud_payments.middleware', config('payhub.cloud_payments_middleware', [])), [CloudPaymentsMiddleware::class]))
    ->group(function (): void {
        Route::post('{action}', [CloudPaymentsController::class, 'action'])
            ->whereIn('action', ['check', 'pay', 'fail', 'subscription'])
            ->name('payhub.cloud-payments.action');
    });
