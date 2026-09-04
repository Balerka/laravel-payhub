<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;

class RefundsControllerTest extends TestCase
{
    public function test_refund_data_returns_current_users_transactions(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $otherUser = User::query()->create(['name' => 'Other']);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => 'cp_user',
            'amount' => 990,
            'fee' => 39,
            'status' => true,
            'gateway' => 'cloud_payments',
        ]);
        Order::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'paid',
        ]);
        Transaction::query()->create([
            'user_id' => $otherUser->id,
            'transaction_id' => 'cp_other',
            'amount' => 990,
            'fee' => 39,
            'status' => true,
            'gateway' => 'cloud_payments',
        ]);

        $this->actingAs($user)
            ->getJson('/payhub/refunds/data')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transactions.0.transaction_id', 'cp_user')
            ->assertJsonCount(1, 'transactions');
    }

    public function test_user_can_refund_completed_transaction(): void
    {
        $this->app->instance(CloudPaymentsClient::class, new class extends CloudPaymentsClient
        {
            public function __construct() {}

            public function getPayment(string $transactionId): array|false
            {
                return ['Status' => 'Completed'];
            }

            public function refund(string $transactionId, float $amount, ?string $requestId = null): bool
            {
                return $transactionId === 'cp_user' && $amount === 990.0 && filled($requestId);
            }
        });

        $user = User::query()->create(['name' => 'User']);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => 'cp_user',
            'amount' => 990,
            'fee' => 39,
            'status' => true,
            'gateway' => 'cloud_payments',
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/refunds/refund', [
                'transaction_id' => $transaction->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transaction.status', false)
            ->assertJsonPath('transaction.amount', 0);

        $this->assertFalse($transaction->refresh()->status);
        $this->assertSame('cancelled', $order->refresh()->status);
    }

    public function test_partial_refund_request_voids_entire_authorized_transaction(): void
    {
        $this->app->instance(CloudPaymentsClient::class, new class extends CloudPaymentsClient
        {
            public function __construct() {}

            public function getPayment(string $transactionId): array|false
            {
                return ['Status' => 'Authorized'];
            }

            public function voidPayment(string $transactionId, ?string $requestId = null): bool
            {
                return $transactionId === 'cp_authorized' && filled($requestId);
            }
        });

        $user = User::query()->create(['name' => 'User']);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => 'cp_authorized',
            'amount' => 990,
            'fee' => 39,
            'status' => true,
            'gateway' => 'cloud_payments',
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'authorized',
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/refunds/refund', [
                'transaction_id' => $transaction->id,
                'amount' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transaction.status', false)
            ->assertJsonPath('transaction.amount', 0);

        $this->assertFalse($transaction->refresh()->status);
        $this->assertSame('cancelled', $order->refresh()->status);
    }

    public function test_user_can_refund_test_transaction_without_cloud_payments(): void
    {
        $cloudPayments = new class extends CloudPaymentsClient
        {
            public bool $paymentRequested = false;

            public function getPayment(string $transactionId): array|false
            {
                $this->paymentRequested = true;

                return false;
            }
        };

        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User']);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => 'test_refund',
            'amount' => 990,
            'fee' => 0,
            'status' => true,
            'gateway' => 'test',
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => 990,
            'currency' => 'RUB',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/refunds/refund', [
                'transaction_id' => $transaction->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFalse($cloudPayments->paymentRequested);
        $this->assertFalse($transaction->refresh()->status);
        $this->assertSame('cancelled', $order->refresh()->status);
    }
}
