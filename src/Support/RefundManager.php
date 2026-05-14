<?php

namespace Balerka\LaravelPayhub\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        if (! $transaction->transaction_id || ! $transaction->status) {
            return null;
        }

        $payment = $this->cloudPayments->getPayment((string) $transaction->transaction_id);
        $paymentStatus = is_array($payment) ? (string) ($payment['Status'] ?? '') : '';
        $refundAmount = min((float) ($amount ?? $transaction->amount), (float) $transaction->amount);

        $refunded = match ($paymentStatus) {
            'Authorized' => $this->cloudPayments->voidPayment((string) $transaction->transaction_id),
            'Completed' => $this->cloudPayments->refund((string) $transaction->transaction_id, $refundAmount),
            default => false,
        };

        if (! $refunded) {
            return null;
        }

        $remainingAmount = round((float) $transaction->amount - $refundAmount, 2);

        DB::transaction(function () use ($transaction, $remainingAmount, $paymentStatus): void {
            if ($remainingAmount <= 0) {
                $transaction->update([
                    'amount' => 0,
                    'fee' => 0,
                    'status' => false,
                ]);

                $transaction->order?->update(['status' => 'cancelled']);

                return;
            }

            $updates = ['amount' => $remainingAmount];

            if ($paymentStatus === 'Authorized') {
                $updates['fee'] = 0;
            }

            $transaction->update($updates);
            $transaction->order?->update(['status' => 'paid']);
        });

        return [
            'amount' => $refundAmount,
            'remaining_amount' => max($remainingAmount, 0),
            'payment_status' => $paymentStatus,
        ];
    }
}
