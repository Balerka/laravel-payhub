<?php

namespace Balerka\LaravelPayhub\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class SubscriptionManager
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    /**
     * @param  array{description: string, amount: float|int|string, email?: string|null, start_date?: DateTimeInterface|string|null, interval?: string|null, period?: int|string|null, require_confirmation?: bool}  $data
     */
    public function restart(Model $subscription, array $data): bool
    {
        $amount = (float) $data['amount'];
        $description = (string) $data['description'];

        $result = $this->cloudPayments->updateSubscription(
            (string) $subscription->subscription_id,
            [
                'Description' => $description,
                'Amount' => $amount,
                'RequireConfirmation' => (bool) ($data['require_confirmation'] ?? true),
                'StartDate' => $this->startDate($data['start_date'] ?? null),
                'Interval' => $data['interval'] ?? null,
                'Period' => isset($data['period']) ? (int) $data['period'] : null,
                'CustomerReceipt' => $this->receipt(
                    $description,
                    $amount,
                    (string) ($data['email'] ?? ''),
                ),
            ],
        );

        if (! $result) {
            return false;
        }

        $subscription->update([
            'amount' => $amount,
            'description' => $description,
            'interval' => $data['interval'] ?? $subscription->interval,
            'period' => isset($data['period']) ? (int) $data['period'] : $subscription->period,
            'status' => true,
        ]);

        return true;
    }

    private function startDate(DateTimeInterface|string|null $startDate): string
    {
        if ($startDate instanceof DateTimeInterface) {
            return $startDate->format(DateTimeInterface::ATOM);
        }

        if (is_string($startDate) && $startDate !== '') {
            return $startDate;
        }

        return now()->addDay()->toAtomString();
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(string $description, float $amount, string $email): array
    {
        return [
            'Items' => [[
                'Label' => $description,
                'Price' => $amount,
                'Quantity' => 1,
                'Amount' => $amount,
                'Vat' => null,
                'Method' => 1,
                'Object' => 4,
                'MeasurementUnit' => 'payment',
            ]],
            'Email' => $email,
            'Amounts' => [
                'Electronic' => $amount,
            ],
        ];
    }
}
