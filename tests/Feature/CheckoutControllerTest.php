<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    public function test_checkout_data_returns_gateway_and_cards(): void
    {
        config()->set('payhub.gateway', 'test');

        $user = User::query()->create(['name' => 'User']);
        $card = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'tok_card',
            'last4' => '4242',
            'bank' => 'Test Bank',
            'brand' => 'Visa',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/payhub/checkout/data')
            ->assertOk()
            ->assertJsonPath('currencyCode', 'RUB')
            ->assertJsonPath('gateway.code', 'test')
            ->assertJsonPath('gateway.enabled', true)
            ->assertJsonPath('gateway.testMode', true)
            ->assertJsonPath('cards.0.id', $card->id)
            ->assertJsonPath('selectedCardId', $card->id);
    }

    public function test_checkout_data_uses_configured_gateway(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.public_id', 'pk_test');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->getJson('/payhub/checkout/data')
            ->assertOk()
            ->assertJsonPath('gateway.code', 'cloud_payments')
            ->assertJsonPath('gateway.enabled', true)
            ->assertJsonPath('gateway.testMode', false)
            ->assertJsonPath('gateway.publicId', 'pk_test');
    }

    public function test_checkout_order_creates_pending_order(): void
    {
        config()->set('payhub.gateway', 'test');

        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', [
                'amount' => 990,
                'currency' => 'RUB',
                'description' => 'Premium',
                'items' => [
                    [
                        'label' => 'Premium',
                        'price' => 590,
                        'quantity' => 1,
                    ],
                    [
                        'label' => 'Boost',
                        'price' => 400,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order.amount', 990)
            ->assertJsonPath('order.currency', 'RUB')
            ->assertJsonPath('payment.gateway', 'test')
            ->assertJsonPath('payment.description', 'Premium')
            ->assertJsonPath('payment.items.0.label', 'Premium')
            ->assertJsonPath('payment.items.1.label', 'Boost');

        $order = Order::query()->where('user_id', $user->id)->where('status', 'pending')->firstOrFail();

        $this->assertSame('Premium', $order->receipt['items'][0]['label']);
        $this->assertSame('Boost', $order->receipt['items'][1]['label']);
        $this->assertEquals(990.0, $order->receipt['amounts']['electronic']);
    }

    public function test_checkout_order_rejects_receipt_with_wrong_total(): void
    {
        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', [
                'amount' => 990,
                'currency' => 'RUB',
                'description' => 'Premium',
                'items' => [
                    [
                        'label' => 'Premium',
                        'price' => 500,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_package_does_not_register_checkout_page(): void
    {
        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->get('/payhub/checkout')
            ->assertNotFound();
    }

    public function test_checkout_order_can_be_cancelled(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount' => 990,
            'currency' => 'RUB',
            'description' => 'Premium',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->deleteJson("/payhub/checkout/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFalse(Order::query()->whereKey($order->id)->exists());
    }

    public function test_checkout_order_charges_selected_cloud_payments_card(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.public_id', 'pk_test');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $this->fakeCloudPaymentsCharge([
            'Success' => true,
            'Model' => [
                'TransactionId' => 'cp_token_123',
                'TotalFee' => 39,
            ],
        ]);

        $user = User::query()->create([
            'name' => 'User',
            'email' => 'user@example.com',
        ]);
        $card = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'card-token',
            'last4' => '4242',
            'bank' => 'Test Bank',
            'brand' => 'Visa',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', [
                'amount' => 990,
                'currency' => 'RUB',
                'description' => 'Premium',
                'card_id' => $card->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('flow', 'saved_card')
            ->assertJsonPath('transaction.transaction_id', 'cp_token_123');

        $order = Order::query()->firstOrFail();

        $this->assertSame('paid', $order->status);
        $this->assertSame(1, Transaction::query()->where('transaction_id', 'cp_token_123')->count());
    }

    public function test_checkout_order_returns_error_when_saved_cloud_payments_card_charge_fails(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.public_id', 'pk_test');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $this->fakeCloudPaymentsCharge([
            'Success' => false,
            'Model' => [
                'CardHolderMessage' => 'Payment declined',
            ],
        ]);

        $user = User::query()->create(['name' => 'User']);
        $card = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'card-token',
            'last4' => '4242',
            'bank' => 'Test Bank',
            'brand' => 'Visa',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', [
                'amount' => 990,
                'currency' => 'RUB',
                'description' => 'Premium',
                'card_id' => $card->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Payment declined');

        $this->assertSame('pending', Order::query()->firstOrFail()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeCloudPaymentsCharge(array $response): void
    {
        $this->app->instance(CloudPaymentsClient::class, new class($response) extends CloudPaymentsClient
        {
            /**
             * @param  array<string, mixed>  $response
             */
            public function __construct(private readonly array $response) {}

            /**
             * @return array<string, mixed>
             */
            public function chargeByToken(Card $card, Order $order, string $email, string $ipAddress): array
            {
                return $this->response;
            }
        });
    }
}
