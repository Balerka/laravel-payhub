<?php

namespace Balerka\LaravelPayhub\Support;

use Illuminate\Database\Eloquent\Model;

class RefundManager
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    /**
     * @return array{amount: float, remaining_amount: float, payment_status: string}|null
     */
    public function refund(Model $transaction, ?float $amount = null): ?array
    {
        if ($transaction->getKey() === null) {
            return null;
        }

        return $transaction->getConnection()->transaction(function () use ($transaction, $amount): ?array {
            $lockedOrder = $transaction->order()->lockForUpdate()->first();
            $lockedTransaction = $transaction->newQuery()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedTransaction || ! $lockedTransaction->transaction_id || ! $lockedTransaction->status) {
                return null;
            }

            $currentAmount = round((float) $lockedTransaction->amount, 2);
            $requestedAmount = round((float) ($amount ?? $currentAmount), 2);

            if ($currentAmount <= 0 || $requestedAmount <= 0) {
                return null;
            }

            $refund = match (GatewayResolver::forTransaction($lockedTransaction->gateway)) {
                'test' => [
                    'amount' => min($requestedAmount, $currentAmount),
                    'payment_status' => 'Completed',
                ],
                'cloud_payments' => $this->refundCloudPayment($lockedTransaction, $requestedAmount, $currentAmount),
                default => null,
            };

            if ($refund === null) {
                return null;
            }

            $refundAmount = $refund['amount'];
            $paymentStatus = $refund['payment_status'];
            $remainingAmount = round($currentAmount - $refundAmount, 2);

            if ($remainingAmount <= 0) {
                $lockedTransaction->update([
                    'amount' => 0,
                    'fee' => 0,
                    'vat' => 0,
                    'status' => false,
                ]);

                $lockedOrder?->update(['status' => 'cancelled']);
            } else {
                $lockedTransaction->update(['amount' => $remainingAmount]);
                $lockedOrder?->update(['status' => 'paid']);
            }

            return [
                'amount' => $refundAmount,
                'remaining_amount' => max($remainingAmount, 0),
                'payment_status' => $paymentStatus,
            ];
        });
    }

    /**
     * @return array{amount: float, payment_status: string}|null
     */
    private function refundCloudPayment(Model $transaction, float $requestedAmount, float $currentAmount): ?array
    {
        $transactionId = (string) $transaction->transaction_id;
        $payment = $this->cloudPayments->getPayment($transactionId);

        if (! is_array($payment)) {
            return null;
        }

        $paymentStatus = (string) ($payment['Status'] ?? '');
        $refundAmount = $paymentStatus === 'Authorized'
            ? $currentAmount
            : min($requestedAmount, $currentAmount);
        $requestId = $this->requestId($transaction, $paymentStatus, $refundAmount);

        $refunded = match ($paymentStatus) {
            'Authorized' => $this->cloudPayments->voidPayment($transactionId, $requestId),
            'Completed' => $this->cloudPayments->refund($transactionId, $refundAmount, $requestId),
            default => false,
        };

        if (! $refunded) {
            return null;
        }

        return [
            'amount' => $refundAmount,
            'payment_status' => $paymentStatus,
        ];
    }

    private function requestId(Model $transaction, string $paymentStatus, float $amount): string
    {
        return hash('sha256', implode('|', [
            (string) config('app.key'),
            (string) $transaction->transaction_id,
            number_format((float) $transaction->amount, 2, '.', ''),
            number_format($amount, 2, '.', ''),
            $paymentStatus === 'Authorized' ? 'void' : 'refund',
        ]));
    }
}
