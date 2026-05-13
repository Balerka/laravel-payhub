<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Support\PayhubModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudPaymentsController
{
    public function action(string $action, Request $request): JsonResponse
    {
        return match ($action) {
            'check' => $this->check($request),
            'pay' => $this->pay($request),
            'fail' => $this->fail($request),
            default => response()->json(['code' => 12]),
        };
    }

    private function check(Request $request): JsonResponse
    {
        $order = $this->order($request);

        if (! $order) {
            return response()->json(['code' => 10]);
        }

        if ((float) $order->amount !== $this->amount($request)) {
            $order->update(['status' => 'failed']);

            return response()->json(['code' => 12]);
        }

        return response()->json(['code' => 0]);
    }

    private function pay(Request $request): JsonResponse
    {
        $order = $this->order($request);

        if (! $order) {
            return response()->json(['code' => 10]);
        }

        $transactionId = $this->stringInput($request, 'transaction_id', 'TransactionId');

        if (! $transactionId) {
            return response()->json(['code' => 12]);
        }

        $transactionModel = PayhubModels::transaction();
        $transaction = $transactionModel::query()->firstOrCreate(
            ['transaction_id' => $transactionId],
            [
                'user_id' => $order->user_id,
                'amount' => (float) $order->amount,
                'fee' => $this->fee($request),
                'status' => true,
                'gateway' => 'CloudPayments',
            ],
        );

        $order->update([
            'transaction_id' => $transaction->id,
            'status' => 'paid',
        ]);

        $this->storeCard($request, (int) $order->user_id);

        return response()->json(['code' => 0]);
    }

    private function fail(Request $request): JsonResponse
    {
        $this->order($request)?->update(['status' => 'failed']);

        return response()->json(['code' => 0]);
    }

    private function order(Request $request): ?Model
    {
        $orderId = $this->intInput($request, 'order_id', 'InvoiceId');

        if (! $orderId) {
            return null;
        }

        $orderModel = PayhubModels::order();

        return $orderModel::query()->whereKey($orderId)->first();
    }

    private function storeCard(Request $request, int $userId): void
    {
        $token = $this->stringInput($request, 'token', 'Token');
        $last4 = $this->stringInput($request, 'card_last_four', 'CardLastFour');
        $brand = $this->stringInput($request, 'card_type', 'CardType');

        if (! $token || ! $last4 || ! $brand) {
            return;
        }

        $cardModel = PayhubModels::card();
        $hasDefaultCard = $cardModel::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->exists();

        $cardModel::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $userId,
                'last4' => $last4,
                'bank' => $this->stringInput($request, 'issuer', 'Issuer'),
                'brand' => $brand,
                'is_default' => ! $hasDefaultCard,
            ],
        );
    }

    private function amount(Request $request): float
    {
        return (float) ($this->numericInput($request, 'amount', 'Amount') ?? 0);
    }

    private function fee(Request $request): float
    {
        return (float) ($this->numericInput($request, 'total_fee', 'TotalFee') ?? 0);
    }

    private function stringInput(Request $request, string $normalizedKey, string $legacyKey): ?string
    {
        $value = $request->input($normalizedKey, $request->input($legacyKey));

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function intInput(Request $request, string $normalizedKey, string $legacyKey): ?int
    {
        $value = $request->input($normalizedKey, $request->input($legacyKey));

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function numericInput(Request $request, string $normalizedKey, string $legacyKey): int|float|string|null
    {
        return $request->input($normalizedKey, $request->input($legacyKey));
    }
}
