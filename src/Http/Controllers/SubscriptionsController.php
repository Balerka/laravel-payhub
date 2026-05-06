<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Models\Subscription;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionsController
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    public function data(Request $request): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn (Subscription $subscription): array => $this->subscriptionPayload($subscription))
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
        $data = $request->validate([
            'subscription_id' => ['required', 'string'],
        ]);

        $subscription = Subscription::query()
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

    /**
     * @return array{id: int, subscription_id: string, status: bool, next_transaction_at: string|null, amount: float|null, currency: string, description: string|null, interval: string|null, period: int|null}
     */
    private function subscriptionPayload(Subscription $subscription): array
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
}
