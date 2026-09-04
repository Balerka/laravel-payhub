<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Subscription;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Support\SubscriptionManager;
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
            'description' => 'Premium monthly',
            'interval' => 'Month',
            'period' => 1,
            'gateway' => 'test',
            'status' => true,
            'next_transaction_at' => now()->addMonth(),
        ]);
        Subscription::query()->create([
            'user_id' => $otherUser->id,
            'subscription_id' => 'sub_other',
            'gateway' => 'test',
            'status' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/payhub/subscriptions/data')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('subscriptions.0.subscription_id', 'sub_user')
            ->assertJsonPath('subscriptions.0.amount', 990)
            ->assertJsonPath('subscriptions.0.currency', 'RUB')
            ->assertJsonPath('subscriptions.0.description', 'Premium monthly')
            ->assertJsonPath('subscriptions.0.interval', 'Month')
            ->assertJsonPath('subscriptions.0.period', 1)
            ->assertJsonCount(1, 'subscriptions');
    }

    public function test_user_can_cancel_subscription(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');

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
            'gateway' => 'cloud_payments',
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

    public function test_subscription_restart_updates_cloud_payments_and_local_schedule(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');

        $cloudPayments = new class extends CloudPaymentsClient
        {
            /**
             * @var array<string, mixed>|null
             */
            public ?array $updateParams = null;

            public function updateSubscription(string $subscriptionId, array $updateParams = []): bool
            {
                $this->updateParams = $updateParams;

                return $subscriptionId === 'sub_restart';
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_restart',
            'amount' => 990,
            'currency' => 'rub',
            'description' => 'Premium monthly',
            'interval' => 'Month',
            'period' => 1,
            'gateway' => 'cloud_payments',
            'status' => false,
        ]);

        $this->assertTrue($this->app->make(SubscriptionManager::class)->restart($subscription));
        $this->assertArrayHasKey('StartDate', $cloudPayments->updateParams);
        $this->assertSame('RUB', $cloudPayments->updateParams['Currency']);
        $this->assertEquals(990.0, $cloudPayments->updateParams['Amount']);
        $this->assertTrue($subscription->refresh()->status);
        $this->assertNotNull($subscription->next_transaction_at);
    }

    public function test_subscription_manager_cancels_remote_subscriptions_before_user_deletion(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');

        $cloudPayments = new class extends CloudPaymentsClient
        {
            /**
             * @var array<int, string>
             */
            public array $cancelled = [];

            public function getSubscriptions(int|string $accountId): array
            {
                return [
                    ['Id' => 'sub_active', 'Status' => 'Active'],
                    ['Id' => 'sub_cancelled', 'Status' => 'Cancelled'],
                ];
            }

            public function cancelSubscription(string $subscriptionId): bool
            {
                $this->cancelled[] = $subscriptionId;

                return true;
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_active',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);

        $this->assertTrue($this->app->make(SubscriptionManager::class)->cancelForUser($user));
        $this->assertSame(['sub_active'], $cloudPayments->cancelled);
        $this->assertFalse($subscription->refresh()->status);
    }

    public function test_subscription_manager_keeps_local_subscription_active_when_remote_lookup_fails(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');

        $this->app->instance(CloudPaymentsClient::class, new class extends CloudPaymentsClient
        {
            public function getSubscriptions(int|string $accountId): array|false
            {
                return false;
            }
        });

        $user = User::query()->create(['name' => 'User']);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_active',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);

        $this->assertFalse($this->app->make(SubscriptionManager::class)->cancelForUser($user));
        $this->assertTrue($subscription->refresh()->status);
    }
}
