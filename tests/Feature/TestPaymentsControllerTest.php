<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;

class TestPaymentsControllerTest extends TestCase
{
    public function test_test_payment_creates_transaction_and_card(): void
    {
        config()->set('payhub.gateways.test.commission', 0.1);
        config()->set('payhub.gateways.test.vat', 1.22);

        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/payments/test/pay', [
                'amount' => 1200,
                'description' => 'Test payment',
                'card_token' => 'tok_test',
                'card_last4' => '4242',
                'card_brand' => 'visa',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('card.last4', '4242');

        $this->assertSame(1, Transaction::query()->where('user_id', $user->id)->count());
        $this->assertEquals(120.0, Transaction::query()->where('user_id', $user->id)->value('fee'));
        $this->assertSame(1, Card::query()->where('user_id', $user->id)->count());
    }

    public function test_test_payment_endpoint_is_disabled_for_another_gateway(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');

        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/payments/test/pay', [
                'amount' => 1200,
                'description' => 'Test payment',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'payment_disabled');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_test_payment_cannot_pay_order_with_another_amount(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 1200,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/payments/test/pay', [
                'order_id' => $order->id,
                'amount' => 1,
                'currency' => 'RUB',
                'description' => 'Test payment',
            ])
            ->assertConflict();

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_repeated_test_payment_reuses_order_transaction(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 1200,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);
        $payload = [
            'order_id' => $order->id,
            'amount' => 1200,
            'currency' => 'RUB',
            'description' => 'Test payment',
        ];

        $this->actingAs($user)->postJson('/payhub/payments/test/pay', $payload)->assertOk();
        $transactionId = $order->refresh()->transaction_id;
        $this->actingAs($user)->postJson('/payhub/payments/test/pay', $payload)->assertOk();

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame($transactionId, $order->refresh()->transaction_id);
    }

    public function test_failed_test_payment_does_not_store_transaction_by_default(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 1200,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/payments/test/pay', [
                'order_id' => $order->id,
                'amount' => 1200,
                'currency' => 'RUB',
                'description' => 'Failed test payment',
                'status' => false,
            ])
            ->assertOk()
            ->assertJsonPath('transaction', null);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertNull($order->transaction_id);
    }

    public function test_failed_test_payment_stores_transaction_when_enabled(): void
    {
        config()->set('payhub.store_failed_transactions', true);

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 1200,
            'currency' => 'RUB',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/payments/test/pay', [
                'order_id' => $order->id,
                'amount' => 1200,
                'currency' => 'RUB',
                'description' => 'Stored failed test payment',
                'status' => false,
                'transaction_id' => 'test_failed_stored',
            ])
            ->assertOk()
            ->assertJsonPath('transaction.status', false);

        $transaction = Transaction::query()->where('transaction_id', 'test_failed_stored')->firstOrFail();

        $this->assertFalse($transaction->status);
        $this->assertSame($transaction->id, $order->refresh()->transaction_id);
        $this->assertSame('failed', $order->status);
    }
}
