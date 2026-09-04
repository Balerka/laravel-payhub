<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Events\SubscriptionStored;
use Balerka\LaravelPayhub\Http\Requests\TestPaymentRequest;
use Balerka\LaravelPayhub\Support\GatewayFees;
use Balerka\LaravelPayhub\Support\GatewayResolver;
use Balerka\LaravelPayhub\Support\PayhubModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestPaymentsController
{
    public function store(TestPaymentRequest $request): JsonResponse
    {
        if (GatewayResolver::active() !== 'test' || ! GatewayResolver::enabled('test')) {
            return response()->json([
                'ok' => false,
                'error' => __('Payment disabled'),
                'error_code' => 'payment_disabled',
            ], 403);
        }

        $data = $request->validated();

        $result = DB::transaction(function () use ($request, $data): array {
            $userId = (int) $request->user()->id;
            $order = $this->resolveOrder($userId, $data);
            $transaction = $order ? $this->attachedTransaction($order, $data) : null;
            $transaction ??= $this->storeTransaction($userId, $data);

            if ($order) {
                $this->assertTransactionAvailable($transaction, $order);

                if ($order->transaction_id === null) {
                    $order->update([
                        'transaction_id' => $transaction->id,
                        'status' => $transaction->status ? 'paid' : 'failed',
                    ]);
                }
            } else {
                $order = $this->storeOrder($userId, $data, $transaction);
            }

            $card = $this->storeCard($request->user()->id, $data);
            $subscription = $this->storeSubscription($request->user()->id, $data);

            if ($subscription) {
                event(new SubscriptionStored($subscription, $order));
            }

            return compact('transaction', 'order', 'card', 'subscription');
        });

        return response()->json([
            'ok' => true,
            'transaction' => $result['transaction'],
            'order' => $result['order'],
            'card' => $result['card'],
            'subscription' => $result['subscription'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveOrder(int $userId, array $data): ?Model
    {
        if (! isset($data['order_id'])) {
            return null;
        }

        $orderModel = PayhubModels::order();
        $order = $orderModel::query()
            ->where('user_id', $userId)
            ->whereKey($data['order_id'])
            ->lockForUpdate()
            ->first();

        abort_if(! $order, 409, __('Checkout request conflicts with an existing payment.'));
        abort_if(
            ! $this->amountsMatch((float) $order->amount, (float) $data['amount'])
            || strtoupper((string) $order->currency) !== $this->currency($data),
            409,
            __('Checkout request conflicts with an existing payment.'),
        );
        abort_if(
            $order->transaction_id === null && $order->status !== 'pending',
            409,
            __('Checkout request conflicts with an existing payment.'),
        );

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeTransaction(int $userId, array $data): Model
    {
        $amount = (float) $data['amount'];
        $fee = GatewayFees::fee($amount, 'test');
        $transactionModel = PayhubModels::transaction();
        $transaction = $transactionModel::query()->createOrFirst(
            ['transaction_id' => $data['transaction_id'] ?? 'test_'.Str::uuid()],
            [
                'user_id' => $userId,
                'amount' => $amount,
                'fee' => $fee,
                'vat' => GatewayFees::vat($fee, 'test'),
                'status' => (bool) ($data['status'] ?? true),
                'gateway' => 'test',
            ],
        );
        $transaction = $transactionModel::query()
            ->whereKey($transaction->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless(
            $this->transactionMatches($transaction, $userId, $data),
            409,
            __('Checkout request conflicts with an existing payment.'),
        );

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeOrder(int $userId, array $data, Model $transaction): Model
    {
        $orderModel = PayhubModels::order();
        $existingOrder = $orderModel::query()
            ->where('transaction_id', $transaction->id)
            ->lockForUpdate()
            ->first();

        if ($existingOrder) {
            abort_unless(
                (int) $existingOrder->user_id === $userId
                && $this->amountsMatch((float) $existingOrder->amount, (float) $data['amount'])
                && strtoupper((string) $existingOrder->currency) === $this->currency($data)
                && $existingOrder->status === ($transaction->status ? 'paid' : 'failed'),
                409,
                __('Checkout request conflicts with an existing payment.'),
            );

            return $existingOrder;
        }

        return $orderModel::query()->create([
            'user_id' => $userId,
            'transaction_id' => $transaction->id,
            'amount' => (float) $data['amount'],
            'currency' => $this->currency($data),
            'receipt' => $this->receipt($data),
            'status' => $transaction->status ? 'paid' : 'failed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function attachedTransaction(Model $order, array $data): ?Model
    {
        if ($order->transaction_id === null) {
            return null;
        }

        $transactionModel = PayhubModels::transaction();
        $transaction = $transactionModel::query()
            ->whereKey($order->transaction_id)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $transaction
            && $this->transactionMatches($transaction, (int) $order->user_id, $data)
            && $order->status === ($transaction->status ? 'paid' : 'failed'),
            409,
            __('Checkout request conflicts with an existing payment.'),
        );

        return $transaction;
    }

    private function assertTransactionAvailable(Model $transaction, Model $order): void
    {
        $orderModel = PayhubModels::order();
        $usedByAnotherOrder = $orderModel::query()
            ->where('transaction_id', $transaction->id)
            ->whereKeyNot($order->getKey())
            ->exists();

        abort_if(
            $usedByAnotherOrder,
            409,
            __('Checkout request conflicts with an existing payment.'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transactionMatches(Model $transaction, int $userId, array $data): bool
    {
        return (int) $transaction->user_id === $userId
            && (bool) $transaction->status === (bool) ($data['status'] ?? true)
            && $this->amountsMatch((float) $transaction->amount, (float) $data['amount'])
            && GatewayResolver::forTransaction($transaction->gateway) === 'test';
    }

    private function amountsMatch(float $first, float $second): bool
    {
        return round($first, 2) === round($second, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function currency(array $data): string
    {
        return strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB'))));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function receipt(array $data): ?array
    {
        if (isset($data['receipt']) && is_array($data['receipt'])) {
            return $data['receipt'];
        }

        if (empty($data['items'])) {
            return null;
        }

        return [
            'items' => $data['items'],
            'email' => '',
            'amounts' => [
                'electronic' => (float) $data['amount'],
            ],
            'currency' => strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB')))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCard(int $userId, array $data): ?Model
    {
        if (empty($data['card_token']) || empty($data['card_last4']) || empty($data['card_brand'])) {
            return null;
        }

        $cardModel = PayhubModels::card();
        $hasDefaultCard = $cardModel::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->exists();

        $card = $cardModel::query()->createOrFirst(
            ['token' => $data['card_token']],
            [
                'user_id' => $userId,
                'last4' => $data['card_last4'],
                'bank' => $data['card_bank'] ?? null,
                'brand' => $data['card_brand'],
                'is_default' => ! $hasDefaultCard,
            ],
        );

        abort_if(
            (int) $card->user_id !== $userId,
            409,
            __('Checkout request conflicts with an existing payment.'),
        );

        $card->update([
            'last4' => $data['card_last4'],
            'bank' => $data['card_bank'] ?? null,
            'brand' => $data['card_brand'],
        ]);

        return $card;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSubscription(int $userId, array $data): ?Model
    {
        if (empty($data['subscription_id'])) {
            return null;
        }

        $subscriptionModel = PayhubModels::subscription();

        $attributes = [
            'user_id' => $userId,
            'amount' => (float) ($data['subscription_amount'] ?? $data['amount']),
            'currency' => strtoupper((string) ($data['subscription_currency'] ?? $data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB')))),
            'description' => $data['subscription_description'] ?? $data['description'],
            'interval' => $data['interval'] ?? null,
            'period' => $data['period'] ?? null,
            'gateway' => 'test',
            'status' => true,
            'next_transaction_at' => $data['next_transaction_at'] ?? null,
        ];
        $subscription = $subscriptionModel::query()->createOrFirst(
            ['subscription_id' => $data['subscription_id']],
            $attributes,
        );
        $subscription = $subscriptionModel::query()
            ->whereKey($subscription->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        abort_if(
            (int) $subscription->user_id !== $userId
            || GatewayResolver::forTransaction($subscription->getAttribute('gateway')) !== 'test',
            409,
            __('Checkout request conflicts with an existing payment.'),
        );

        $subscription->update($attributes);

        return $subscription;
    }

}
