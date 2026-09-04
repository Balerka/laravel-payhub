<?php

namespace Balerka\LaravelPayhub\Support;

use Illuminate\Database\Eloquent\Model;

class PaymentManager
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    public function confirm(Model $transaction): bool
    {
        if (! $transaction->transaction_id) {
            return false;
        }

        return match (GatewayResolver::forTransaction($transaction->gateway)) {
            'test' => true,
            'cloud_payments' => $this->confirmCloudPaymentsTransaction($transaction),
            default => false,
        };
    }

    private function confirmCloudPaymentsTransaction(Model $transaction): bool
    {
        $transactionId = (string) $transaction->transaction_id;
        $confirmed = $this->cloudPayments->confirmPayment(
            $transactionId,
            (float) $transaction->amount,
            hash('sha256', implode('|', [
                (string) config('app.key'),
                $transactionId,
                'confirm',
            ])),
        );

        if ($confirmed) {
            return true;
        }

        $payment = $this->cloudPayments->getPayment($transactionId);

        return is_array($payment) && ($payment['Status'] ?? null) === 'Completed';
    }
}
