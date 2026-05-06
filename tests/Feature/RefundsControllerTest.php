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
            'gateway' => 'CloudPayments',
        ]);
        Order::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
            'status' => 'paid',
        ]);
        Transaction::query()->create([
            'user_id' => $otherUser->id,
            'transaction_id' => 'cp_other',
            'amount' => 990,
            'fee' => 39,
            'status' => true,
            'gateway' => 'CloudPayments',
        ]);

        $this->actingAs($user)
            ->getJson('/payhub/refunds/data')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transactions.0.transaction_id', 'cp_user')
            ->assertJsonPath('transactions.0.order.description', 'Premium')
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

            public function refund(string $transactionId, float $amount): bool
            {
                return $transactionId === 'cp_user' && $amount === 990.0;
            }
        });

        $user = User::query()->create(['name' => 'User']);
        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => 'cp_user',
            'amount' => 990,
            'fee' => 39,
            'status' => true,
            'gateway' => 'CloudPayments',
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
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
}
