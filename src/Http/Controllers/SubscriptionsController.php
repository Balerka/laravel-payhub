<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Support\GatewayResolver;
use Balerka\LaravelPayhub\Support\PayhubModels;
use Balerka\LaravelPayhub\Support\SubscriptionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SubscriptionsController
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
        private readonly SubscriptionManager $subscriptions,
    ) {}

    public function data(Request $request): JsonResponse
    {
        $subscriptionModel = PayhubModels::subscription();

        $subscriptions = $subscriptionModel::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get();

        $this->hydrateMissingNextTransactionDates($subscriptions);

        $subscriptions = $subscriptions
            ->map(fn (Model $subscription): array => $this->subscriptionPayload($subscription))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'currencyCode' => config('payhub.currency', config('app.currency', 'RUB')),
            'subscriptions' => $subscriptions,
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'string'],
        ]);

        $subscriptionModel = PayhubModels::subscription();

        $subscription = $subscriptionModel::query()
            ->where('user_id', $request->user()->id)
            ->where('subscription_id', $data['subscription_id'])
            ->first();

        if (! $subscription) {
            return response()->json([
                'ok' => false,
                'error' => __('Subscription not found'),
            ], 404);
        }

        if (! $subscription->status) {
            return response()->json(['ok' => true]);
        }

        if (! $this->subscriptions->cancel($subscription)) {
            return response()->json([
                'ok' => false,
                'error' => __('Subscription cancel failed'),
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function cancelByEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'card' => ['required', 'digits:4'],
        ]);

        $userModel = (string) config('payhub.user_model');
        $user = $userModel::query()->where('email', $data['email'])->first();

        if (! $user instanceof Model) {
            return response()->json([
                'ok' => false,
                'error' => __('Card not found'),
                'error_code' => 'card_not_found',
            ], 404);
        }

        $cardModel = PayhubModels::card();
        $hasCard = $cardModel::query()
            ->where('user_id', $user->id)
            ->where('last4', $data['card'])
            ->exists();

        if (! $hasCard) {
            return response()->json([
                'ok' => false,
                'error' => __('Card not found'),
                'error_code' => 'card_not_found',
            ], 404);
        }

        $subscriptionModel = PayhubModels::subscription();
        $subscription = $subscriptionModel::query()
            ->where('user_id', $user->id)
            ->where('status', true)
            ->first();

        if (! $subscription) {
            return response()->json([
                'ok' => false,
                'error' => __('Subscription not found'),
                'error_code' => 'subscription_not_found',
            ], 404);
        }

        if (! $this->subscriptions->cancel($subscription)) {
            return response()->json([
                'ok' => false,
                'error' => __('Subscription cancel failed'),
                'error_code' => 'subscription_cancel_failed',
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{id: int, subscription_id: string, amount: float|null, currency: string|null, description: string|null, interval: string|null, period: int|null, status: bool, next_transaction_at: string|null}
     */
    private function subscriptionPayload(Model $subscription): array
    {
        return [
            'id' => $subscription->id,
            'subscription_id' => $subscription->subscription_id,
            'amount' => $subscription->amount === null ? null : (float) $subscription->amount,
            'currency' => $subscription->currency,
            'description' => $subscription->description,
            'interval' => $subscription->interval,
            'period' => $subscription->period === null ? null : (int) $subscription->period,
            'status' => (bool) $subscription->status,
            'next_transaction_at' => $subscription->next_transaction_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, Model>  $subscriptions
     */
    private function hydrateMissingNextTransactionDates(Collection $subscriptions): void
    {
        $missingDates = $subscriptions->filter(
            fn (Model $subscription): bool => (bool) $subscription->status
                && ! $subscription->next_transaction_at
                && GatewayResolver::forTransaction($subscription->getAttribute('gateway')) === 'cloud_payments'
        );

        if ($missingDates->isEmpty()) {
            return;
        }

        foreach ($missingDates as $subscription) {
            $subscriptionFromApi = $this->cloudPayments->getSubscription($subscription->subscription_id);

            if (! $subscriptionFromApi || empty($subscriptionFromApi['NextTransactionDateIso'])) {
                continue;
            }

            $subscription->update(['next_transaction_at' => $subscriptionFromApi['NextTransactionDateIso']]);
        }
    }
}
