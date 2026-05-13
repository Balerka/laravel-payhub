<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Support\PayhubModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SubscriptionsController
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    public function data(Request $request): JsonResponse
    {
        if ($response = $this->paymentDisabledResponse()) {
            return $response;
        }

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
            'subscriptions' => $subscriptions,
            'currencyCode' => config('payhub.currency', config('app.currency', 'RUB')),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        if ($response = $this->paymentDisabledResponse()) {
            return $response;
        }

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
                'error' => 'Subscription not found.',
            ], 404);
        }

        if (! $subscription->status) {
            return response()->json(['ok' => true]);
        }

        if (! $this->cloudPayments->cancelSubscription($subscription->subscription_id)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unable to cancel subscription.',
            ], 422);
        }

        $subscription->update(['status' => false]);

        return response()->json(['ok' => true]);
    }

    public function cancelByEmail(Request $request): JsonResponse
    {
        if ($response = $this->paymentDisabledResponse()) {
            return $response;
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'card' => ['required', 'digits:4'],
        ]);

        $userModel = (string) config('payhub.user_model');
        $user = $userModel::query()->where('email', $data['email'])->first();

        if (! $user instanceof Model) {
            return response()->json([
                'ok' => false,
                'error' => 'Card not found.',
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
                'error' => 'Card not found.',
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
                'error' => 'Subscription not found.',
                'error_code' => 'subscription_not_found',
            ], 404);
        }

        if (! $this->cloudPayments->cancelSubscription($subscription->subscription_id)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unable to cancel subscription.',
                'error_code' => 'subscription_cancel_failed',
            ], 422);
        }

        $subscription->update(['status' => false]);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{id: int, subscription_id: string, status: bool, next_transaction_at: string|null, amount: float|null, currency: string, description: string|null, interval: string|null, period: int|null}
     */
    private function subscriptionPayload(Model $subscription): array
    {
        return [
            'id' => $subscription->id,
            'subscription_id' => $subscription->subscription_id,
            'status' => (bool) $subscription->status,
            'next_transaction_at' => $subscription->next_transaction_at?->toISOString(),
            'amount' => $subscription->amount === null ? null : (float) $subscription->amount,
            'currency' => $subscription->currency,
            'description' => $subscription->description,
            'interval' => $subscription->interval,
            'period' => $subscription->period,
        ];
    }

    private function paymentDisabledResponse(): ?JsonResponse
    {
        if (config('payhub.gateway')) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'error' => 'Payment disabled.',
            'error_code' => 'payment_disabled',
        ]);
    }

    /**
     * @param  Collection<int, Model>  $subscriptions
     */
    private function hydrateMissingNextTransactionDates(Collection $subscriptions): void
    {
        $missingDates = $subscriptions->filter(
            fn (Model $subscription): bool => (bool) $subscription->status && ! $subscription->next_transaction_at
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
