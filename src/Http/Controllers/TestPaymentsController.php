<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Http\Requests\TestPaymentRequest;
use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Subscription;
use Balerka\LaravelPayhub\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestPaymentsController
{
    public function store(TestPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = DB::transaction(function () use ($request, $data): array {
            $amount = (float) $data['amount'];
            $status = (bool) ($data['status'] ?? true);

            $transaction = Transaction::query()->create([
                'user_id' => $request->user()->id,
                'transaction_id' => $data['transaction_id'] ?? 'test_'.Str::uuid(),
                'amount' => $amount,
                'fee' => $this->fee($amount),
                'status' => $status,
                'source' => $data['source'] ?? 'TestPayments',
            ]);

            $order = $this->resolveOrder($request->user()->id, $data, $transaction);
            $card = $this->storeCard($request->user()->id, $data);
            $subscription = $this->storeSubscription($request->user()->id, $data);

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
    private function resolveOrder(int $userId, array $data, Transaction $transaction): ?Order
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

        return Order::query()->create([
            'user_id' => $userId,
            'transaction_id' => $transaction->id,
            'amount' => (float) $data['amount'],
            'currency' => strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB')))),
            'description' => $data['description'] ?? null,
            'receipt' => $this->receipt($data),
            'status' => $transaction->status ? 'paid' : 'failed',
        ]);
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
            'description' => (string) ($data['description'] ?? 'Payment'),
        ];
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
    private function storeSubscription(int $userId, array $data): ?Subscription
    {
        if (empty($data['subscription_id'])) {
            return null;
        }

        return Subscription::query()->updateOrCreate(
            ['subscription_id' => $data['subscription_id']],
            [
                'user_id' => $userId,
                'amount' => (float) $data['amount'],
                'currency' => strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB')))),
                'description' => $data['description'] ?? null,
                'interval' => $data['interval'] ?? null,
                'period' => $data['period'] ?? null,
                'status' => true,
                'next_transaction_at' => $data['next_transaction_at'] ?? null,
            ],
        );
    }

    private function fee(float $amount): float
    {
        $commission = (float) config('payhub.gateways.test.commission', 0);
        $vat = (float) config('payhub.gateways.test.vat', 1);

        return round($amount * $commission * $vat, 2);
    }
}
