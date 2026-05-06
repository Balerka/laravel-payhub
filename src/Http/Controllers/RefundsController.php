<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundsController
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    public function data(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->with('order')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('transaction_id')
            ->latest('id')
            ->get()
            ->map(fn (Transaction $transaction): array => $this->transactionPayload($transaction))
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

        $transaction = Transaction::query()
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

        $payment = $this->cloudPayments->getPayment($transaction->transaction_id);
        $status = is_array($payment) ? (string) ($payment['Status'] ?? '') : '';
        $amount = min((float) ($data['amount'] ?? $transaction->amount), (float) $transaction->amount);

        $refunded = match ($status) {
            'Authorized' => $this->cloudPayments->voidPayment($transaction->transaction_id),
            'Completed' => $this->cloudPayments->refund($transaction->transaction_id, $amount),
            default => false,
        };

        if (! $refunded) {
            return response()->json([
                'ok' => false,
                'error' => 'Unable to refund transaction.',
            ], 422);
        }

        DB::transaction(function () use ($transaction, $amount): void {
            $remainingAmount = round((float) $transaction->amount - $amount, 2);

            if ($remainingAmount <= 0) {
                $transaction->update([
                    'amount' => 0,
                    'fee' => 0,
                    'status' => false,
                ]);

                $transaction->order?->update(['status' => 'cancelled']);

                return;
            }

            $transaction->update(['amount' => $remainingAmount]);
            $transaction->order?->update(['status' => 'paid']);
        });

        return response()->json([
            'ok' => true,
            'transaction' => $this->transactionPayload($transaction->refresh()),
        ]);
    }

    /**
     * @return array{id: int, transaction_id: string|null, amount: float, fee: float, income: float, status: bool, source: string|null, created_at: string|null, order: array{id: int, status: string, amount: float, currency: string, description: string|null}|null}
     */
    private function transactionPayload(Transaction $transaction): array
    {
        $order = $transaction->order;

        return [
            'id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'amount' => (float) $transaction->amount,
            'fee' => (float) $transaction->fee,
            'income' => (float) $transaction->income,
            'status' => (bool) $transaction->status,
            'source' => $transaction->source,
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
