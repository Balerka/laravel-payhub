<?php

namespace Balerka\LaravelReactPayments\Http\Controllers;

use Balerka\LaravelReactPayments\Http\Requests\TestPaymentRequest;
use Balerka\LaravelReactPayments\Models\Card;
use Balerka\LaravelReactPayments\Models\Order;
use Balerka\LaravelReactPayments\Models\Product;
use Balerka\LaravelReactPayments\Models\Subscription;
use Balerka\LaravelReactPayments\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestPaymentsController
{
    public function store(TestPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = DB::transaction(function () use ($request, $data): array {
            $product = isset($data['product_id']) ? Product::query()->find($data['product_id']) : null;
            $amount = (float) ($data['amount'] ?? $product?->price ?? 0);
            $status = (bool) ($data['status'] ?? true);

            $transaction = Transaction::query()->create([
                'user_id' => $request->user()->id,
                'transaction_id' => $data['transaction_id'] ?? 'test_'.Str::uuid(),
                'amount' => $amount,
                'fee' => $this->fee($amount),
                'status' => $status,
                'source' => $data['source'] ?? 'TestPayments',
            ]);

            $order = $this->resolveOrder($request->user()->id, $data, $transaction, $product);
            $card = $this->storeCard($request->user()->id, $data);
            $subscription = $this->storeSubscription($request->user()->id, $data, $product);

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
    private function resolveOrder(int $userId, array $data, Transaction $transaction, ?Product $product): ?Order
    {
        if (isset($data['order_id'])) {
            $order = Order::query()
                ->where('user_id', $userId)
                ->whereKey($data['order_id'])
                ->first();

            if ($order) {
                $order->update([
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->status ? 'paid' : 'failed',
                ]);
            }

            return $order;
        }

        if (! $product) {
            return null;
        }

        return Order::query()->create([
            'user_id' => $userId,
            'product_id' => $product->id,
            'transaction_id' => $transaction->id,
            'status' => $transaction->status ? 'paid' : 'failed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCard(int $userId, array $data): ?Card
    {
        if (empty($data['card_token']) || empty($data['card_last4']) || empty($data['card_brand'])) {
            return null;
        }

        $hasDefaultCard = Card::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->exists();

        return Card::query()->updateOrCreate(
            ['token' => $data['card_token']],
            [
                'user_id' => $userId,
                'last4' => $data['card_last4'],
                'bank' => $data['card_bank'] ?? null,
                'brand' => $data['card_brand'],
                'is_default' => ! $hasDefaultCard,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSubscription(int $userId, array $data, ?Product $product): ?Subscription
    {
        if (empty($data['subscription_id']) || ! $product) {
            return null;
        }

        return Subscription::query()->updateOrCreate(
            ['subscription_id' => $data['subscription_id']],
            [
                'user_id' => $userId,
                'product_id' => $product->id,
                'status' => true,
                'next_transaction_at' => $data['next_transaction_at'] ?? null,
            ],
        );
    }

    private function fee(float $amount): float
    {
        $commission = (float) config('payments.gateways.test.commission', 0);
        $vat = (float) config('payments.gateways.test.vat', 1);

        return round($amount * $commission * $vat, 2);
    }
}
