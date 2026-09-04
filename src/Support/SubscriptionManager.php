<?php

namespace Balerka\LaravelPayhub\Support;

use Balerka\LaravelPayhub\Events\SubscriptionStored;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SubscriptionManager
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    public function restart(Model $subscription): bool
    {
        $gateway = $this->gatewayCode($subscription);

        if ($gateway === 'test') {
            $subscription->update(['status' => true]);

            return true;
        }

        if ($gateway !== 'cloud_payments') {
            return false;
        }

        $startDate = now()->addMinute()->toAtomString();
        $updateParams = ['StartDate' => $startDate];

        foreach ([
            'amount' => 'Amount',
            'currency' => 'Currency',
            'description' => 'Description',
            'interval' => 'Interval',
            'period' => 'Period',
        ] as $attribute => $parameter) {
            $value = $subscription->getAttribute($attribute);

            if ($value !== null && $value !== '') {
                $updateParams[$parameter] = $attribute === 'currency'
                    ? strtoupper((string) $value)
                    : $value;
            }
        }

        $result = $this->cloudPayments->updateSubscription(
            (string) $subscription->subscription_id,
            $updateParams,
        );

        if (! $result) {
            return false;
        }

        $subscription->update([
            'status' => true,
            'next_transaction_at' => $startDate,
        ]);

        return true;
    }

    public function cancel(Model $subscription): bool
    {
        $cancelled = match ($this->gatewayCode($subscription)) {
            'test' => true,
            'cloud_payments' => $this->cloudPayments->cancelSubscription((string) $subscription->subscription_id),
            default => false,
        };

        if (! $cancelled) {
            return false;
        }

        $subscription->update(['status' => false]);

        return true;
    }

    public function cancelForUser(Model $user): bool
    {
        $subscriptionModel = PayhubModels::subscription();
        $localSubscriptions = $subscriptionModel::query()
            ->where('user_id', $user->getKey())
            ->where('status', true)
            ->get();

        $localGateways = $localSubscriptions
            ->map(fn (Model $subscription): string => $this->gatewayCode($subscription))
            ->unique();

        if ($localGateways->contains('')) {
            return false;
        }

        $shouldCancelCloudPayments = $localGateways->contains('cloud_payments')
            || GatewayResolver::active() === 'cloud_payments';

        if ($shouldCancelCloudPayments) {
            $remoteSubscriptions = $this->cloudPayments->getSubscriptions($user->getKey());

            if ($remoteSubscriptions === false) {
                return false;
            }

            $remoteSubscriptionIds = collect($remoteSubscriptions)
                ->filter(fn (array $subscription): bool => in_array($subscription['Status'] ?? null, ['Active', 'PastDue'], true))
                ->pluck('Id')
                ->filter(fn (mixed $subscriptionId): bool => is_string($subscriptionId) && $subscriptionId !== '')
                ->unique();

            foreach ($remoteSubscriptionIds as $subscriptionId) {
                if (! $this->cloudPayments->cancelSubscription($subscriptionId)) {
                    return false;
                }
            }
        }

        $localSubscriptions->each(function (Model $subscription): void {
            $subscription->update(['status' => false]);
        });

        return true;
    }

    public function requiresSubscription(Model $order): bool
    {
        return $this->rawSubscriptionData($order) !== null;
    }

    public function createFromOrderPayment(Request $request, Model $order, ?string $token = null): ?Model
    {
        return $order->getConnection()->transaction(function () use ($request, $order, $token): ?Model {
            $lockedOrder = $order->newQuery()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return null;
            }

            return $this->createFromLockedOrder($request, $lockedOrder, $token);
        });
    }

    private function createFromLockedOrder(Request $request, Model $order, ?string $token): ?Model
    {
        $data = $this->subscriptionDataFromOrder($order);

        if ($data === null) {
            return null;
        }

        $gateway = $this->orderGateway($order);
        $subscriptionId = $data['subscription_id'] ?? null;

        if (is_string($subscriptionId) && $subscriptionId !== '') {
            $subscriptionModel = PayhubModels::subscription();
            $subscription = $subscriptionModel::query()
                ->where('subscription_id', $subscriptionId)
                ->first();

            if ($subscription) {
                if (
                    (int) $subscription->user_id !== (int) $order->user_id
                    || GatewayResolver::forTransaction($subscription->getAttribute('gateway')) !== $gateway
                ) {
                    return null;
                }

                return $this->dispatchSubscriptionStored($subscription, $order);
            }

            return $this->dispatchSubscriptionStored(
                $this->storeSubscription(
                    $subscriptionId,
                    (int) $order->user_id,
                    $data,
                    $data['next_transaction_at'] ?? null,
                    gateway: $gateway,
                ),
                $order,
            );
        }

        $subscription = null;

        if ($gateway === 'cloud_payments') {
            $subscription = $this->createCloudPaymentsSubscription($request, $order, $data, $token);
        }

        if ($gateway === 'test') {
            $subscription = $this->storeSubscription(
                (string) ($data['subscription_id'] ?? 'test-subscription-'.$order->id),
                (int) $order->user_id,
                $data,
                $this->nextTransactionDate($data),
                gateway: $gateway,
            );
        }

        if ($subscription) {
            $this->rememberSubscriptionOnOrder($order, (string) $subscription->subscription_id);
        }

        return $this->dispatchSubscriptionStored($subscription, $order);
    }

    /**
     * @param  array{amount: float, description: string, currency?: string|null, interval?: string|null, period?: int|null, start_in?: string|null, params?: array<string, mixed>, subscription_id?: string|null}  $data
     */
    private function createCloudPaymentsSubscription(Request $request, Model $order, array $data, ?string $token): ?Model
    {
        if (! $token) {
            return null;
        }

        $user = $this->findUser((int) $order->user_id);

        if (! $user) {
            return null;
        }

        $interval = $data['interval'] === null ? null : (string) $data['interval'];
        $period = $data['period'] === null ? null : (int) $data['period'];
        $currency = strtoupper((string) ($data['currency'] ?: $order->currency ?: $this->currency($request)));
        $additionalParams = is_array($data['params'] ?? null) ? $data['params'] : [];
        $additionalParams['Currency'] = $currency;
        $response = $this->cloudPayments->createSubscription(
            $token,
            $user,
            (string) ($data['start_in'] ?? trim(($period ?? 1).' '.($interval ?? 'Month'))),
            (float) $data['amount'],
            (string) $data['description'],
            $interval,
            $period,
            $additionalParams,
            $this->subscriptionRequestId($order),
        );

        if (! $response || empty($response['Id'])) {
            return null;
        }

        return $this->storeSubscription(
            (string) $response['Id'],
            (int) $order->user_id,
            $data,
            $response['NextTransactionDateIso'] ?? $response['NextTransactionDate'] ?? null,
            $currency,
            'cloud_payments',
        );
    }

    /**
     * @param  array{amount: float, description: string, currency?: string|null, interval?: string|null, period?: int|null, start_in?: string|null, params?: array<string, mixed>, subscription_id?: string|null}  $data
     */
    private function storeSubscription(
        string $subscriptionId,
        int $userId,
        array $data,
        mixed $nextTransactionAt = null,
        ?string $currency = null,
        ?string $gateway = null,
    ): ?Model
    {
        $subscriptionModel = PayhubModels::subscription();
        $resolvedGateway = GatewayResolver::forTransaction($gateway ?: $this->gatewayCode());

        if ($resolvedGateway === '') {
            return null;
        }

        $attributes = [
            'user_id' => $userId,
            'amount' => (float) $data['amount'],
            'currency' => $currency ?? strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB')))),
            'description' => (string) $data['description'],
            'interval' => $data['interval'] ?? null,
            'period' => $data['period'] ?? null,
            'gateway' => $resolvedGateway,
            'status' => true,
            'next_transaction_at' => $nextTransactionAt,
        ];
        $connection = (new $subscriptionModel)->getConnection();

        return $connection->transaction(function () use ($subscriptionModel, $subscriptionId, $userId, $resolvedGateway, $attributes): ?Model {
            $subscription = $subscriptionModel::query()->createOrFirst(
                ['subscription_id' => $subscriptionId],
                $attributes,
            );
            $subscription = $subscriptionModel::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $subscription->user_id !== $userId
                || GatewayResolver::forTransaction($subscription->getAttribute('gateway')) !== $resolvedGateway
            ) {
                return null;
            }

            $subscription->update($attributes);

            return $subscription;
        });
    }

    /**
     * @return array{amount: float, description: string, currency?: string|null, interval?: string|null, period?: int|null, start_in?: string|null, params?: array<string, mixed>, subscription_id?: string|null, next_transaction_at?: string|null}|null
     */
    private function subscriptionDataFromOrder(Model $order): ?array
    {
        $subscription = $this->rawSubscriptionData($order);

        if ($subscription === null) {
            return null;
        }

        $amount = (float) ($subscription['amount'] ?? 0);
        $description = (string) ($subscription['description'] ?? '');

        if ($amount <= 0 || $description === '') {
            return null;
        }

        return [
            'amount' => $amount,
            'description' => $description,
            'currency' => isset($subscription['currency']) ? (string) $subscription['currency'] : null,
            'interval' => isset($subscription['interval']) ? (string) $subscription['interval'] : null,
            'period' => isset($subscription['period']) ? (int) $subscription['period'] : null,
            'start_in' => isset($subscription['start_in']) ? (string) $subscription['start_in'] : null,
            'params' => is_array($subscription['params'] ?? null) ? $subscription['params'] : [],
            'subscription_id' => isset($subscription['subscription_id']) ? (string) $subscription['subscription_id'] : null,
            'next_transaction_at' => isset($subscription['next_transaction_at']) ? (string) $subscription['next_transaction_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawSubscriptionData(Model $order): ?array
    {
        $receipt = $order->receipt;
        $subscription = is_array($receipt) && is_array($receipt['subscription'] ?? null)
            ? $receipt['subscription']
            : null;

        if ($subscription === null) {
            return null;
        }

        if (
            array_key_exists('enabled', $subscription)
            && $subscription['enabled'] !== null
            && ! filter_var($subscription['enabled'], FILTER_VALIDATE_BOOL)
        ) {
            return null;
        }

        return $subscription;
    }

    private function rememberSubscriptionOnOrder(Model $order, string $subscriptionId): void
    {
        $receipt = is_array($order->receipt) ? $order->receipt : [];
        $subscription = is_array($receipt['subscription'] ?? null) ? $receipt['subscription'] : [];

        if (($subscription['subscription_id'] ?? null) === $subscriptionId) {
            return;
        }

        $subscription['subscription_id'] = $subscriptionId;
        $receipt['subscription'] = $subscription;

        $order->forceFill(['receipt' => $receipt])->saveQuietly();
    }

    private function nextTransactionDate(array $data): ?string
    {
        $startIn = $data['start_in'] ?? null;

        return is_string($startIn) && $startIn !== ''
            ? now()->add($startIn)->toAtomString()
            : null;
    }

    private function subscriptionRequestId(Model $order): string
    {
        return hash('sha256', implode('|', [
            (string) config('app.key'),
            $order->getConnectionName() ?? '',
            $order->getTable(),
            (string) $order->getKey(),
            'subscription',
        ]));
    }

    private function dispatchSubscriptionStored(?Model $subscription, Model $order): ?Model
    {
        if ($subscription) {
            event(new SubscriptionStored($subscription, $order));
        }

        return $subscription;
    }

    private function findUser(int $userId): ?Model
    {
        $userModel = (string) config('payhub.user_model');

        if (! is_a($userModel, Model::class, true)) {
            return null;
        }

        return $userModel::query()->whereKey($userId)->first();
    }

    private function orderGateway(Model $order): string
    {
        if ($order->transaction_id !== null) {
            $transactionModel = PayhubModels::transaction();
            $gateway = $transactionModel::query()
                ->whereKey($order->transaction_id)
                ->value('gateway');
            $resolvedGateway = GatewayResolver::forTransaction(
                is_string($gateway) ? $gateway : null,
            );

            if ($resolvedGateway !== '') {
                return $resolvedGateway;
            }
        }

        return $this->gatewayCode();
    }

    private function currency(Request $request, string $fallback = ''): string
    {
        $value = $request->input('currency', $request->input('Currency'));

        return strtoupper((string) ($value ?: $fallback ?: config('payhub.currency', config('app.currency', 'RUB'))));
    }

    private function gatewayCode(?Model $subscription = null): string
    {
        if ($subscription) {
            return GatewayResolver::forTransaction($subscription->getAttribute('gateway'));
        }

        return GatewayResolver::active();
    }
}
