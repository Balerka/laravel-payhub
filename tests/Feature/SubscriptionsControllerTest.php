<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Subscription;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;

class SubscriptionsControllerTest extends TestCase
{
    public function test_subscription_data_returns_current_users_subscriptions(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $otherUser = User::query()->create(['name' => 'Other']);
        Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_user',
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
            'interval' => 'Month',
            'period' => 1,
            'status' => true,
            'next_transaction_at' => now()->addMonth(),
        ]);
        Subscription::query()->create([
            'user_id' => $otherUser->id,
            'subscription_id' => 'sub_other',
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
            'status' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/payhub/subscriptions/data')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('subscriptions.0.subscription_id', 'sub_user')
            ->assertJsonPath('subscriptions.0.description', 'Premium')
            ->assertJsonPath('subscriptions.0.amount', 990)
            ->assertJsonCount(1, 'subscriptions');
    }

    public function test_user_can_cancel_subscription(): void
    {
        $this->app->instance(CloudPaymentsClient::class, new class extends CloudPaymentsClient
        {
            public function __construct() {}

            public function cancelSubscription(string $subscriptionId): bool
            {
                return $subscriptionId === 'sub_user';
            }
        });

        $user = User::query()->create(['name' => 'User']);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_user',
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
            'status' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/subscriptions/cancel', [
                'subscription_id' => 'sub_user',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFalse($subscription->refresh()->status);
    }
}
