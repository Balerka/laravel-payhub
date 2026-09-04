<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Events\SubscriptionOrderCreated;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Support\GatewayResolver;
use Balerka\LaravelPayhub\Support\PayhubModels;
use Balerka\LaravelPayhub\Support\SubscriptionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudPaymentsController
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
        private readonly SubscriptionManager $subscriptions,
    ) {}

    public function action(Request $request, string $action): JsonResponse
    {
        return match ($action) {
            'check' => $this->check($request),
            'pay' => $this->pay($request),
            'fail' => $this->fail($request),
            'subscription' => $this->subscription($request),
            default => response()->json(['code' => 12]),
        };
    }

    private function check(Request $request): JsonResponse
    {
        $order = $this->order($request);

        if ($order) {
            return response()->json(['code' => $this->orderValidationCode($request, $order)]);
        }

        $subscription = $this->subscriptionFromRequest($request);

        if (! $subscription) {
            return response()->json(['code' => 10]);
        }

        return response()->json(['code' => $this->subscriptionValidationCode($request, $subscription)]);
    }

    private function pay(Request $request): JsonResponse
    {
        $transactionId = $this->stringInput($request, 'transaction_id', 'TransactionId');

        if (! $transactionId) {
            return response()->json(['code' => 12]);
        }

        $order = $this->order($request);

        if ($order) {
            $validationCode = $this->orderValidationCode($request, $order);

            if ($validationCode !== 0) {
                return response()->json(['code' => $validationCode]);
            }

            if (! $this->recordOrderPayment($request, $order, $transactionId)) {
                return response()->json(['code' => 12]);
            }
        } else {
            $subscription = $this->subscriptionFromRequest($request);

            if (! $subscription) {
                return response()->json(['code' => 10]);
            }

            $validationCode = $this->subscriptionValidationCode($request, $subscription);

            if ($validationCode !== 0) {
                return response()->json(['code' => $validationCode]);
            }

            $order = $this->recordSubscriptionPayment($request, $transactionId, $subscription);

            if (! $order) {
                return response()->json(['code' => 12]);
            }
        }

        $this->storeCard($request, (int) $order->user_id);
        $requiresSubscription = $this->subscriptions->requiresSubscription($order);
        $subscription = $this->subscriptions->createFromOrderPayment(
            $request,
            $order->refresh(),
            $this->stringInput($request, 'token', 'Token'),
        );

        if ($requiresSubscription && ! $subscription) {
            return response()->json(['code' => 13]);
        }

        $this->syncSubscriptionFromRequest($request, (int) $order->user_id);

        return response()->json(['code' => 0]);
    }

    private function fail(Request $request): JsonResponse
    {
        $order = $this->order($request);

        if ($order) {
            $validationCode = $this->orderValidationCode($request, $order);

            if ($validationCode !== 0) {
                return response()->json(['code' => $validationCode]);
            }
        } else {
            $order = $this->subscriptionFailureOrder($request);
        }

        if (! $order || in_array($order->status, ['paid', 'authorized'], true)) {
            return response()->json(['code' => 0]);
        }

        $alreadyFailed = $order->status === 'failed';

        if (! $alreadyFailed) {
            $order->update(['status' => 'failed']);
        }

        $this->postponeSubscriptionRetry($request, $order);

        return response()->json(['code' => 0]);
    }

    private function subscription(Request $request): JsonResponse
    {
        $accountId = $this->intInput($request, 'account_id', 'AccountId');

        if (! $accountId) {
            return response()->json(['code' => 11]);
        }

        $subscription = $this->syncSubscriptionFromRequest($request, $accountId);

        return response()->json(['code' => $subscription ? 0 : 12]);
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

    private function orderValidationCode(Request $request, Model $order): int
    {
        $accountId = $this->intInput($request, 'account_id', 'AccountId');

        if ($accountId !== null && $accountId !== (int) $order->user_id) {
            return 11;
        }

        if (! $this->amountsMatch((float) $order->amount, $this->amount($request))) {
            return 12;
        }

        return $this->requestCurrencyMatches($request, (string) $order->currency) ? 0 : 12;
    }

    private function subscriptionValidationCode(Request $request, Model $subscription): int
    {
        $accountId = $this->intInput($request, 'account_id', 'AccountId');

        if ($accountId !== null && $accountId !== (int) $subscription->user_id) {
            return 11;
        }

        $amount = $this->amount($request);
        $subscriptionAmount = (float) ($subscription->amount ?? 0);

        if ($amount <= 0 || ($subscriptionAmount > 0 && ! $this->amountsMatch($subscriptionAmount, $amount))) {
            return 12;
        }

        return $this->requestCurrencyMatches($request, (string) ($subscription->currency ?? '')) ? 0 : 12;
    }

    private function recordOrderPayment(Request $request, Model $order, string $transactionId): bool
    {
        return DB::transaction(function () use ($request, $order, $transactionId): bool {
            $orderModel = PayhubModels::order();
            $lockedOrder = $orderModel::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return false;
            }

            $transactionModel = PayhubModels::transaction();

            if ($lockedOrder->transaction_id !== null) {
                $attachedTransaction = $transactionModel::query()
                    ->whereKey($lockedOrder->transaction_id)
                    ->lockForUpdate()
                    ->first();

                if (! $attachedTransaction
                    || (string) $attachedTransaction->transaction_id !== $transactionId
                    || ! $this->transactionMatchesOrder($attachedTransaction, $lockedOrder)) {
                    return false;
                }

                $lockedOrder->update(['status' => 'paid']);

                return true;
            }

            $transaction = $transactionModel::query()->firstOrCreate(
                ['transaction_id' => $transactionId],
                $this->transactionData($request, (int) $lockedOrder->user_id, (float) $lockedOrder->amount),
            );

            $transaction = $transactionModel::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->transactionMatchesOrder($transaction, $lockedOrder)) {
                return false;
            }

            $usedByAnotherOrder = $orderModel::query()
                ->where('transaction_id', $transaction->id)
                ->whereKeyNot($lockedOrder->getKey())
                ->exists();

            if ($usedByAnotherOrder) {
                return false;
            }

            $lockedOrder->update([
                'transaction_id' => $transaction->id,
                'status' => 'paid',
            ]);

            return true;
        });
    }

    private function transactionMatchesOrder(Model $transaction, Model $order): bool
    {
        return (int) $transaction->user_id === (int) $order->user_id
            && (bool) $transaction->status
            && $this->amountsMatch((float) $transaction->amount, (float) $order->amount)
            && GatewayResolver::forTransaction($transaction->gateway) === 'cloud_payments';
    }

    private function recordSubscriptionPayment(Request $request, string $transactionId, Model $subscription): ?Model
    {
        $subscriptionAmount = (float) ($subscription->amount ?? 0);
        $amount = $subscriptionAmount > 0 ? $subscriptionAmount : $this->amount($request);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($request, $subscription, $transactionId, $amount): ?Model {
            $transactionModel = PayhubModels::transaction();
            $transaction = $transactionModel::query()->firstOrCreate(
                ['transaction_id' => $transactionId],
                $this->transactionData($request, (int) $subscription->user_id, $amount),
            );

            $transaction = $transactionModel::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $transaction->user_id !== (int) $subscription->user_id
                || ! $transaction->status
                || ! $this->amountsMatch((float) $transaction->amount, $amount)
                || GatewayResolver::forTransaction($transaction->gateway) !== 'cloud_payments'
            ) {
                return null;
            }

            $orderModel = PayhubModels::order();
            $existingOrder = $orderModel::query()
                ->where('transaction_id', $transaction->id)
                ->first();

            if ($existingOrder) {
                return $existingOrder;
            }

            $order = $this->createSubscriptionOrder($request, $subscription, $amount);

            if (! $order) {
                return null;
            }

            $order->update([
                'transaction_id' => $transaction->id,
                'status' => 'authorized',
            ]);

            return $order;
        });
    }

    /**
     * @return array{user_id: int, amount: float, fee: float, vat: float|null, status: bool, gateway: string}
     */
    private function transactionData(Request $request, int $userId, float $amount, bool $status = true): array
    {
        return [
            'user_id' => $userId,
            'amount' => $amount,
            'fee' => $this->fee($request),
            'vat' => $this->vat($request),
            'status' => $status,
            'gateway' => 'CloudPayments',
        ];
    }

    private function subscriptionFailureOrder(Request $request): ?Model
    {
        $subscription = $this->subscriptionFromRequest($request);

        if (! $subscription || $this->subscriptionValidationCode($request, $subscription) !== 0) {
            return null;
        }

        $amount = (float) ($subscription->amount ?? 0) ?: $this->amount($request);

        if ($amount <= 0) {
            return null;
        }

        $transactionId = $this->stringInput($request, 'transaction_id', 'TransactionId');

        if (! $transactionId) {
            return null;
        }

        return DB::transaction(function () use ($request, $subscription, $transactionId, $amount): ?Model {
            $transactionModel = PayhubModels::transaction();
            $transaction = $transactionModel::query()->firstOrCreate(
                ['transaction_id' => $transactionId],
                $this->transactionData($request, (int) $subscription->user_id, $amount, false),
            );
            $transaction = $transactionModel::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $transaction->user_id !== (int) $subscription->user_id
                || $transaction->status
                || ! $this->amountsMatch((float) $transaction->amount, $amount)
                || GatewayResolver::forTransaction($transaction->gateway) !== 'cloud_payments'
            ) {
                return null;
            }

            $orderModel = PayhubModels::order();
            $existingOrder = $orderModel::query()
                ->where('transaction_id', $transaction->id)
                ->first();

            if ($existingOrder) {
                return $existingOrder;
            }

            $order = $this->createSubscriptionOrder($request, $subscription, $amount);

            if ($order) {
                $order->update(['transaction_id' => $transaction->id]);
            }

            return $order;
        });
    }

    private function createSubscriptionOrder(Request $request, Model $subscription, ?float $amount = null): ?Model
    {
        $amount ??= $this->amount($request) ?: (float) ($subscription->amount ?? 0);

        if ($amount <= 0) {
            return null;
        }

        $orderModel = PayhubModels::order();
        $order = $orderModel::query()->create([
            'user_id' => $subscription->user_id,
            'amount' => $amount,
            'currency' => $this->currency($request, (string) ($subscription->currency ?? '')),
            'receipt' => [
                'description' => $this->stringInput($request, 'description', 'Description') ?? $subscription->description,
                'subscription_id' => $subscription->subscription_id,
            ],
            'status' => 'pending',
        ]);

        event(new SubscriptionOrderCreated($subscription, $order));

        return $order;
    }

    private function subscriptionFromRequest(Request $request): ?Model
    {
        $subscriptionId = $this->subscriptionWebhookId($request);

        if (! $subscriptionId) {
            return null;
        }

        $subscriptionModel = PayhubModels::subscription();

        $subscription = $subscriptionModel::query()
            ->where('subscription_id', $subscriptionId)
            ->first();

        return $subscription
            && GatewayResolver::forTransaction($subscription->getAttribute('gateway')) === 'cloud_payments'
                ? $subscription
                : null;
    }

    private function syncSubscriptionFromRequest(Request $request, int $userId): ?Model
    {
        $subscriptionId = $this->subscriptionWebhookId($request);

        if (! $subscriptionId) {
            return null;
        }

        $subscriptionModel = PayhubModels::subscription();
        $connection = (new $subscriptionModel)->getConnection();

        return $connection->transaction(function () use ($request, $userId, $subscriptionId, $subscriptionModel): ?Model {
            $subscription = $subscriptionModel::query()
                ->where('subscription_id', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if (
                ! $subscription
                || (int) $subscription->user_id !== $userId
                || GatewayResolver::forTransaction($subscription->getAttribute('gateway')) !== 'cloud_payments'
            ) {
                return null;
            }

            $subscription->update($this->subscriptionUpdateData($request, $userId, $subscription));

            return $subscription;
        });
    }

    private function postponeSubscriptionRetry(Request $request, ?Model $order): void
    {
        $delayDays = config('payhub.gateways.cloud_payments.subscription_retry_delay_days');

        if ($delayDays === null) {
            return;
        }

        $delayDays = (int) $delayDays;
        $maxAttempts = (int) config('payhub.gateways.cloud_payments.subscription_retry_max_attempts', 4);
        $subscriptionId = $this->stringInput($request, 'subscription_id', 'SubscriptionId');

        if ($delayDays <= 0 || $maxAttempts <= 0 || ! $subscriptionId) {
            return;
        }

        if (data_get($order?->receipt, '_payhub.subscription_retry_scheduled_at')) {
            return;
        }

        if (! $this->isRetryableSubscriptionFailure($request)) {
            return;
        }

        if ($this->failedSubscriptionAttempts($request, $order, $delayDays, $maxAttempts) >= $maxAttempts) {
            return;
        }

        $startDate = now()->addDays($delayDays)->toAtomString();

        if (! $this->cloudPayments->updateSubscription($subscriptionId, ['StartDate' => $startDate])) {
            return;
        }

        $subscriptionModel = PayhubModels::subscription();
        $subscriptionModel::query()
            ->where('subscription_id', $subscriptionId)
            ->update([
                'status' => true,
                'next_transaction_at' => $startDate,
            ]);

        if ($order) {
            $receipt = is_array($order->receipt) ? $order->receipt : [];
            $payhub = is_array($receipt['_payhub'] ?? null) ? $receipt['_payhub'] : [];
            $payhub['subscription_retry_scheduled_at'] = $startDate;
            $receipt['_payhub'] = $payhub;
            $order->forceFill(['receipt' => $receipt])->saveQuietly();
        }
    }

    /**
     * @return array{user_id: int, amount?: float|null, currency: string, gateway: string, description?: string|null, interval?: string|null, period?: int|null, status: bool, next_transaction_at?: string|null}
     */
    private function subscriptionUpdateData(Request $request, int $userId, ?Model $existingSubscription = null): array
    {
        $status = $this->stringInput($request, 'status', 'Status');

        $data = [
            'user_id' => $userId,
            'currency' => $this->currency($request, (string) ($existingSubscription?->currency ?? '')),
            'gateway' => 'cloud_payments',
            'status' => $status === null || in_array($status, ['Active', 'PastDue'], true),
        ];

        $optional = [
            'amount' => $this->amount($request) ?: null,
            'description' => $this->stringInput($request, 'description', 'Description'),
            'interval' => $this->stringInput($request, 'interval', 'Interval'),
            'period' => $this->intInput($request, 'period', 'Period'),
            'next_transaction_at' => $this->nextTransactionAt($request),
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private function isRetryableSubscriptionFailure(Request $request): bool
    {
        $reasonCode = $this->intInput($request, 'reason_code', 'ReasonCode');

        if (! $reasonCode) {
            return false;
        }

        $retryReasonCodes = config('payhub.gateways.cloud_payments.subscription_retry_reason_codes', []);

        return in_array($reasonCode, is_array($retryReasonCodes) ? $retryReasonCodes : [], true);
    }

    private function failedSubscriptionAttempts(Request $request, ?Model $order, int $delayDays, int $maxAttempts): int
    {
        $orderModel = PayhubModels::order();
        $accountId = $this->intInput($request, 'account_id', 'AccountId') ?? (int) ($order?->user_id ?? 0);
        $amount = $this->amount($request) ?: (float) ($order?->amount ?? 0);
        $subscriptionId = $this->stringInput($request, 'subscription_id', 'SubscriptionId');

        return $orderModel::query()
            ->where('status', 'failed')
            ->when($accountId > 0, fn ($query) => $query->where('user_id', $accountId))
            ->when($amount > 0, fn ($query) => $query->where('amount', $amount))
            ->when($subscriptionId, fn ($query) => $query->where(function ($query) use ($subscriptionId): void {
                $query->where('receipt->subscription_id', $subscriptionId)
                    ->orWhere('receipt->subscription->subscription_id', $subscriptionId);
            }))
            ->where('created_at', '>', now()->subDays($delayDays * $maxAttempts))
            ->count();
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

        $card = $cardModel::query()->createOrFirst(
            ['token' => $token],
            [
                'user_id' => $userId,
                'last4' => $last4,
                'bank' => $this->stringInput($request, 'issuer', 'Issuer'),
                'brand' => $brand,
                'is_default' => ! $hasDefaultCard,
            ],
        );

        if ((int) $card->user_id !== $userId) {
            return;
        }

        $card->update([
            'last4' => $last4,
            'bank' => $this->stringInput($request, 'issuer', 'Issuer'),
            'brand' => $brand,
        ]);
    }

    private function amount(Request $request): float
    {
        return (float) ($this->numericInput($request, 'amount', 'Amount') ?? 0);
    }

    private function amountsMatch(float $expected, float $actual): bool
    {
        return round($expected, 2) === round($actual, 2);
    }

    private function requestCurrencyMatches(Request $request, string $expected): bool
    {
        $currency = $this->stringInput($request, 'currency', 'Currency');

        if ($currency === null) {
            return true;
        }

        $expected = $expected ?: (string) config('payhub.currency', config('app.currency', 'RUB'));

        return strtoupper($currency) === strtoupper($expected);
    }

    private function currency(Request $request, string $fallback = ''): string
    {
        return strtoupper((string) ($this->stringInput($request, 'currency', 'Currency') ?: $fallback ?: config('payhub.currency', config('app.currency', 'RUB'))));
    }

    private function fee(Request $request): float
    {
        return (float) ($this->numericInput($request, 'total_fee', 'TotalFee') ?? 0);
    }

    private function vat(Request $request): ?float
    {
        $vat = $this->numericInput($request, 'vat_above_total_fee', 'VatAboveTotalFee');

        return $vat === null ? null : (float) $vat;
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

    private function subscriptionWebhookId(Request $request): ?string
    {
        return $this->stringInput($request, 'id', 'Id')
            ?? $this->stringInput($request, 'subscription_id', 'SubscriptionId');
    }

    private function nextTransactionAt(Request $request): ?string
    {
        return $this->stringInput($request, 'next_transaction_at', 'NextTransactionDateIso')
            ?? $this->stringInput($request, 'next_transaction_date', 'NextTransactionDate');
    }

}
