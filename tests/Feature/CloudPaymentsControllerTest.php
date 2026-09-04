<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Subscription;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Support\PaymentManager;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;

class CloudPaymentsControllerTest extends TestCase
{
    public function test_cloud_payments_check_accepts_valid_order_amount(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 1980,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/check', [
            'InvoiceId' => $order->id,
            'Amount' => 1980,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_cloud_payments_check_accepts_existing_subscription_amount(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_check',
            'amount' => 990,
            'currency' => 'RUB',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/check', [
            'AccountId' => $user->id,
            'SubscriptionId' => 'sub_check',
            'Amount' => 990,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_cloud_payments_pay_marks_order_paid_and_stores_transaction_and_card(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', [
            'InvoiceId' => $order->id,
            'TransactionId' => 'cp_123',
            'Amount' => 990,
            'TotalFee' => 39,
            'Token' => 'card-token',
            'CardLastFour' => '4242',
            'CardType' => 'Visa',
            'Issuer' => 'Test Bank',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(1, Transaction::query()->where('transaction_id', 'cp_123')->count());
        $this->assertSame(1, Card::query()->where('token', 'card-token')->where('is_default', true)->count());
    }

    public function test_cloud_payments_pay_rejects_mismatched_order_payload(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', [
            'InvoiceId' => $order->id,
            'TransactionId' => 'cp_wrong_account',
            'AccountId' => $user->id + 1,
            'Amount' => 990,
            'Currency' => 'RUB',
        ])->assertOk()->assertJsonPath('code', 11);

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', [
            'InvoiceId' => $order->id,
            'TransactionId' => 'cp_wrong_amount',
            'AccountId' => $user->id,
            'Amount' => 991,
            'Currency' => 'RUB',
        ])->assertOk()->assertJsonPath('code', 12);

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', [
            'InvoiceId' => $order->id,
            'TransactionId' => 'cp_wrong_currency',
            'AccountId' => $user->id,
            'Amount' => 990,
            'Currency' => 'USD',
        ])->assertOk()->assertJsonPath('code', 12);

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_cloud_payments_fail_marks_order_failed(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/fail', [
            'InvoiceId' => $order->id,
            'AccountId' => $user->id,
            'Amount' => 990,
            'Currency' => 'RUB',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('failed', $order->refresh()->status);
    }

    public function test_cloud_payments_fail_rejects_mismatched_order_payload(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/fail', [
            'InvoiceId' => $order->id,
            'AccountId' => $user->id,
            'Amount' => 991,
            'Currency' => 'RUB',
        ])
            ->assertOk()
            ->assertJsonPath('code', 12);

        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_cloud_payments_fail_postpones_subscription_retry_when_enabled(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');
        config()->set('payhub.gateways.cloud_payments.subscription_retry_delay_days', 7);
        config()->set('payhub.gateways.cloud_payments.subscription_retry_max_attempts', 4);

        $cloudPayments = new class extends CloudPaymentsClient
        {
            public ?string $subscriptionId = null;

            /**
             * @var array<string, mixed>|null
             */
            public ?array $updateParams = null;

            public function updateSubscription(string $subscriptionId, array $updateParams = []): bool
            {
                $this->subscriptionId = $subscriptionId;
                $this->updateParams = $updateParams;

                return true;
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'receipt' => ['subscription_id' => 'sub_123'],
            'status' => 'pending',
        ]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_123',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/fail', [
            'AccountId' => $user->id,
            'InvoiceId' => $order->id,
            'Amount' => 990,
            'SubscriptionId' => 'sub_123',
            'ReasonCode' => 5051,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('sub_123', $cloudPayments->subscriptionId);
        $this->assertArrayHasKey('StartDate', $cloudPayments->updateParams);
        $this->assertNotNull($subscription->refresh()->next_transaction_at);
    }

    public function test_cloud_payments_fail_does_not_postpone_subscription_retry_after_max_attempts(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');
        config()->set('payhub.gateways.cloud_payments.subscription_retry_delay_days', 7);
        config()->set('payhub.gateways.cloud_payments.subscription_retry_max_attempts', 2);

        $cloudPayments = new class extends CloudPaymentsClient
        {
            public bool $updated = false;

            public function updateSubscription(string $subscriptionId, array $updateParams = []): bool
            {
                $this->updated = true;

                return true;
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'receipt' => ['subscription_id' => 'sub_123'],
            'status' => 'failed',
            'created_at' => now()->subDay(),
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'receipt' => ['subscription_id' => 'sub_123'],
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/fail', [
            'AccountId' => $user->id,
            'InvoiceId' => $order->id,
            'Amount' => 990,
            'SubscriptionId' => 'sub_123',
            'ReasonCode' => 5051,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertFalse($cloudPayments->updated);
    }

    public function test_cloud_payments_subscription_callback_updates_local_subscription_metadata(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_created',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);

        $this->postCloudPaymentsJson('/api/cloudpayments/subscription', [
            'Id' => 'sub_created',
            'AccountId' => $user->id,
            'Amount' => 990,
            'Currency' => 'RUB',
            'Description' => 'Premium monthly',
            'Interval' => 'Month',
            'Period' => 1,
            'Status' => 'Active',
            'NextTransactionDateIso' => '2026-09-30T00:00:00+00:00',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $subscription = Subscription::query()->where('subscription_id', 'sub_created')->firstOrFail();

        $this->assertSame($user->id, $subscription->user_id);
        $this->assertEquals(990.0, $subscription->amount);
        $this->assertSame('RUB', $subscription->currency);
        $this->assertSame('Premium monthly', $subscription->description);
        $this->assertSame('Month', $subscription->interval);
        $this->assertSame(1, $subscription->period);
        $this->assertTrue($subscription->status);
        $this->assertNotNull($subscription->next_transaction_at);
    }

    public function test_cloud_payments_pay_creates_subscription_from_order_payload(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $cloudPayments = new class extends CloudPaymentsClient
        {
            public int $createCalls = 0;

            /**
             * @var array<string, mixed>|null
             */
            public ?array $payload = null;

            public function createSubscription(
                string $token,
                Model $user,
                string $startIn,
                float $amount,
                string $description,
                ?string $interval,
                ?int $period,
                array $additionalParams = [],
                ?string $requestId = null,
            ): ?array {
                $this->createCalls++;
                $this->payload = compact('token', 'startIn', 'amount', 'description', 'interval', 'period', 'additionalParams', 'requestId');

                return [
                    'Id' => 'sub_from_order',
                    'NextTransactionDateIso' => '2026-09-30T00:00:00+00:00',
                ];
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'receipt' => [
                'subscription' => [
                    'amount' => 1990,
                    'currency' => 'RUB',
                    'description' => 'Premium recurrent',
                    'interval' => 'Month',
                    'period' => 1,
                    'start_in' => '7 Day',
                ],
            ],
            'status' => 'pending',
        ]);

        $payload = [
            'InvoiceId' => $order->id,
            'TransactionId' => 'cp_456',
            'Amount' => 990,
            'Token' => 'card-token',
        ];

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);
        $this->postCloudPaymentsJson('/api/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('7 Day', $cloudPayments->payload['startIn']);
        $this->assertEquals(1990.0, $cloudPayments->payload['amount']);
        $this->assertSame('Premium recurrent', $cloudPayments->payload['description']);
        $this->assertSame('RUB', $cloudPayments->payload['additionalParams']['Currency']);
        $this->assertNotEmpty($cloudPayments->payload['requestId']);
        $this->assertSame(1, $cloudPayments->createCalls);
        $this->assertSame(1, Subscription::query()->where('subscription_id', 'sub_from_order')->count());
        $this->assertNotNull(Subscription::query()->where('subscription_id', 'sub_from_order')->firstOrFail()->next_transaction_at);
        $this->assertSame('sub_from_order', $order->refresh()->receipt['subscription']['subscription_id']);
    }

    public function test_cloud_payments_retries_subscription_creation_without_duplicating_payment(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $cloudPayments = new class extends CloudPaymentsClient
        {
            public int $createCalls = 0;

            public bool $succeeds = false;

            /**
             * @var array<int, string|null>
             */
            public array $requestIds = [];

            public function createSubscription(
                string $token,
                Model $user,
                string $startIn,
                float $amount,
                string $description,
                ?string $interval,
                ?int $period,
                array $additionalParams = [],
                ?string $requestId = null,
            ): ?array {
                $this->createCalls++;
                $this->requestIds[] = $requestId;

                return $this->succeeds
                    ? ['Id' => 'sub_after_retry']
                    : null;
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'receipt' => [
                'subscription' => [
                    'amount' => 1990,
                    'currency' => 'RUB',
                    'description' => 'Premium recurrent',
                    'interval' => 'Month',
                    'period' => 1,
                ],
            ],
            'status' => 'pending',
        ]);
        $payload = [
            'InvoiceId' => $order->id,
            'TransactionId' => 'cp_retry_subscription',
            'Amount' => 990,
            'Token' => 'card-token',
        ];

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJsonPath('code', 13);

        $cloudPayments->succeeds = true;

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(2, $cloudPayments->createCalls);
        $this->assertSame($cloudPayments->requestIds[0], $cloudPayments->requestIds[1]);
        $this->assertNotEmpty($cloudPayments->requestIds[0]);
        $this->assertSame(1, Transaction::query()->where('transaction_id', 'cp_retry_subscription')->count());
        $this->assertSame(1, Subscription::query()->where('subscription_id', 'sub_after_retry')->count());
    }

    public function test_cloud_payments_recurring_pay_creates_order_from_existing_subscription(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_renew',
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium recurrent',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);

        $payload = [
            'AccountId' => $user->id,
            'SubscriptionId' => 'sub_renew',
            'TransactionId' => 'cp_renew',
            'Amount' => 990,
        ];

        $this->postCloudPaymentsJson('/api/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);
        $this->postCloudPaymentsJson('/api/cloudpayments/pay', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $order = Order::query()->where('transaction_id', Transaction::query()->where('transaction_id', 'cp_renew')->value('id'))->firstOrFail();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('authorized', $order->status);
        $this->assertEquals(990.0, $order->amount);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Transaction::query()->where('transaction_id', 'cp_renew')->count());
    }

    public function test_cloud_payments_recurring_fail_is_idempotent_by_transaction_id(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_id' => 'sub_failed_renewal',
            'amount' => 990,
            'currency' => 'RUB',
            'gateway' => 'cloud_payments',
            'status' => true,
        ]);
        $payload = [
            'AccountId' => $user->id,
            'SubscriptionId' => 'sub_failed_renewal',
            'TransactionId' => 'cp_failed_renewal',
            'Amount' => 990,
            'Currency' => 'RUB',
        ];

        $this->postCloudPaymentsJson('/api/cloudpayments/fail', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);
        $this->postCloudPaymentsJson('/api/cloudpayments/fail', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(1, Order::query()->where('status', 'failed')->count());
        $this->assertSame(1, Transaction::query()->where('transaction_id', 'cp_failed_renewal')->where('status', false)->count());
    }

    public function test_payment_manager_treats_test_transaction_as_already_confirmed(): void
    {
        $cloudPayments = new class extends CloudPaymentsClient
        {
            public bool $confirmCalled = false;

            public function confirmPayment(string $transactionId, float $amount, ?string $requestId = null): bool
            {
                $this->confirmCalled = true;

                return false;
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => 'test_recurring',
            'amount' => 990,
            'fee' => 0,
            'status' => true,
            'gateway' => 'test',
        ]);

        $this->assertTrue($this->app->make(PaymentManager::class)->confirm($transaction));
        $this->assertFalse($cloudPayments->confirmCalled);
    }

    public function test_cloud_payments_rejects_invalid_signature(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $this->withHeaders(['Content-HMAC' => 'invalid'])
            ->postJson('/api/cloudpayments/check', ['InvoiceId' => 1])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postCloudPaymentsJson(string $uri, array $payload): TestResponse
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $hmac = base64_encode(hash_hmac('sha256', $json, 'secret', true));

        return $this->withHeaders(['Content-HMAC' => $hmac])
            ->json('POST', $uri, $payload);
    }
}
