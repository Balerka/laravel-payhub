<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Support\PayhubModels;
use Balerka\LaravelPayhub\Support\RefundManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundsController
{
    public function __construct(
        private readonly RefundManager $refunds,
    ) {}

    public function data(Request $request): JsonResponse
    {
        $transactionModel = PayhubModels::transaction();

        $transactions = $transactionModel::query()
            ->with('order')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('transaction_id')
            ->latest('id')
            ->get()
            ->map(fn (Model $transaction): array => $this->transactionPayload($transaction))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'transactions' => $transactions,
            'currencyCode' => config('payhub.currency', config('app.currency', 'RUB')),
        ]);
    }

    public function refund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $transactionModel = PayhubModels::transaction();

        $transaction = $transactionModel::query()
            ->with('order')
            ->where('user_id', $request->user()->id)
            ->whereKey((int) $data['transaction_id'])
            ->first();

        if (! $transaction || ! $transaction->transaction_id) {
            return response()->json([
                'ok' => false,
                'error' => 'Transaction not found.',
            ], 404);
        }

        if (! $transaction->status) {
            return response()->json([
                'ok' => false,
                'error' => 'Transaction is already refunded or failed.',
            ], 422);
        }

        $amount = min((float) ($data['amount'] ?? $transaction->amount), (float) $transaction->amount);
        $refunded = $this->refunds->refund($transaction, $amount);

        if (! $refunded) {
            return response()->json([
                'ok' => false,
                'error' => 'Unable to refund transaction.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'transaction' => $this->transactionPayload($transaction->refresh()),
        ]);
    }

    /**
     * @return array{id: int, transaction_id: string|null, amount: float, fee: float, income: float, status: bool, gateway: string|null, created_at: string|null, order: array{id: int, status: string, amount: float, currency: string, description: string|null}|null}
     */
    private function transactionPayload(Model $transaction): array
    {
        $order = $transaction->order;

        return [
            'id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'amount' => (float) $transaction->amount,
            'fee' => (float) $transaction->fee,
            'income' => (float) $transaction->income,
            'status' => (bool) $transaction->status,
            'gateway' => $transaction->gateway,
            'created_at' => $transaction->created_at?->toISOString(),
            'order' => $order ? [
                'id' => $order->id,
                'status' => $order->status,
                'amount' => (float) $order->amount,
                'currency' => $order->currency,
                'description' => $order->description,
            ] : null,
        ];
    }
}
