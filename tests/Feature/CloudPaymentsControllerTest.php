<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;
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
            'description' => 'Premium',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/payhub/payments/cloud-payments/check', [
            'InvoiceId' => $order->id,
            'Amount' => 1980,
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
            'description' => 'Premium',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/payhub/payments/cloud-payments/pay', [
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

    public function test_cloud_payments_fail_marks_order_failed(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
            'status' => 'pending',
        ]);

        $this->postCloudPaymentsJson('/payhub/payments/cloud-payments/fail', [
            'InvoiceId' => $order->id,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('failed', $order->refresh()->status);
    }

    public function test_cloud_payments_rejects_invalid_signature(): void
    {
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $this->withHeaders(['Content-HMAC' => 'invalid'])
            ->postJson('/payhub/payments/cloud-payments/check', ['InvoiceId' => 1])
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
