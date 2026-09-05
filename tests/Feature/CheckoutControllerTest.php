<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Balerka\LaravelPayhub\Support\SubscriptionManager;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
                'idempotency_key' => 'checkout-pending-order',
                'currency' => 'RUB',
                'products' => [
                    $this->product('Premium', 590),
                    $this->product('Boost', 400),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order.amount', 990)
            ->assertJsonPath('order.currency', 'RUB')
            ->assertJsonPath('payment.gateway', 'test')
            ->assertJsonPath('payment.description', 'Premium, Boost')
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
                'idempotency_key' => 'checkout-invalid-receipt',
                'currency' => 'RUB',
                'products' => [$this->product()],
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

    public function test_checkout_order_stores_subscription_payload(): void
    {
        config()->set('payhub.gateway', 'test');

        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', [
                'idempotency_key' => 'checkout-subscription-payload',
                'currency' => 'RUB',
                'products' => [$this->product('Premium', 330, 3, 'шт.')],
                'subscription' => [
                    'currency' => 'RUB',
                    'products' => [$this->product('Premium recurrent', 995, 2, 'месяц')],
                    'interval' => 'Month',
                    'period' => 1,
                    'start_in' => '7 Day',
                    'metadata' => ['reference' => 'plan-10'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment.quantity', 3)
            ->assertJsonPath('payment.price', 330)
            ->assertJsonPath('payment.unit', 'шт.');

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Premium recurrent', $order->receipt['subscription']['description']);
        $this->assertEquals(1990.0, $order->receipt['subscription']['amount']);
        $this->assertSame(2, $order->receipt['subscription']['quantity']);
        $this->assertSame('месяц', $order->receipt['subscription']['unit']);
        $this->assertSame('plan-10', $order->receipt['subscription']['metadata']['reference']);
        $this->assertSame(3.0, (float) $order->receipt['items'][0]['quantity']);
        $this->assertSame(330.0, (float) $order->receipt['items'][0]['price']);
        $this->assertSame('шт.', $order->receipt['items'][0]['measurementUnit']);
    }

    public function test_checkout_rejects_invalid_subscription_schedule(): void
    {
        config()->set('payhub.gateway', 'test');

        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', [
                'idempotency_key' => 'checkout-invalid-subscription',
                'currency' => 'RUB',
                'products' => [$this->product()],
                'subscription' => [
                    'currency' => 'RUB',
                    'products' => [$this->product('Premium recurrent', 1990)],
                    'interval' => 'Year',
                    'period' => 1,
                    'start_in' => 'whenever',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription.interval', 'subscription.start_in']);

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
                'VatAboveTotalFee' => 8.58,
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
                'idempotency_key' => 'checkout-saved-cloud-card',
                'currency' => 'RUB',
                'products' => [$this->product()],
                'card_id' => $card->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('flow', 'saved_card')
            ->assertJsonPath('transaction.transaction_id', 'cp_token_123');

        $order = Order::query()->firstOrFail();

        $this->assertSame('paid', $order->status);
        $this->assertSame(1, Transaction::query()->where('transaction_id', 'cp_token_123')->count());
        $this->assertEquals(8.58, Transaction::query()->where('transaction_id', 'cp_token_123')->value('vat'));
    }

    public function test_saved_card_checkout_is_idempotent(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.public_id', 'pk_test');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $cloudPayments = new class extends CloudPaymentsClient
        {
            public int $charges = 0;

            public function chargeByToken(
                Model $card,
                Model $order,
                string $description,
                string $email,
                string $ipAddress,
                ?string $requestId = null,
            ): array
            {
                $this->charges++;

                return [
                    'Success' => filled($requestId),
                    'Model' => ['TransactionId' => 'cp_idempotent'],
                ];
            }
        };
        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);

        $user = User::query()->create(['name' => 'User', 'email' => 'user@example.com']);
        $card = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'card-token',
            'last4' => '4242',
            'brand' => 'Visa',
            'is_default' => true,
        ]);
        $payload = [
            'idempotency_key' => 'checkout-idempotent-saved-card',
            'currency' => 'RUB',
            'products' => [$this->product()],
            'card_id' => $card->id,
        ];

        $this->actingAs($user)->postJson('/payhub/checkout/orders', $payload)->assertOk();
        $this->actingAs($user)->postJson('/payhub/checkout/orders', $payload)->assertOk();

        $this->assertSame(1, $cloudPayments->charges);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_saved_card_checkout_stays_paid_when_required_subscription_creation_fails(): void
    {
        config()->set('payhub.gateway', 'cloud_payments');
        config()->set('payhub.gateways.cloud_payments.public_id', 'pk_test');
        config()->set('payhub.gateways.cloud_payments.secret', 'secret');

        $cloudPayments = new class extends CloudPaymentsClient
        {
            public int $charges = 0;

            public function chargeByToken(
                Model $card,
                Model $order,
                string $description,
                string $email,
                string $ipAddress,
                ?string $requestId = null,
            ): array
            {
                $this->charges++;

                return [
                    'Success' => true,
                    'Model' => ['TransactionId' => 'cp_subscription_failure'],
                ];
            }
        };
        $this->app->instance(CloudPaymentsClient::class, $cloudPayments);
        $this->app->instance(SubscriptionManager::class, new class extends SubscriptionManager
        {
            public function __construct() {}

            public function requiresSubscription(Model $order): bool
            {
                return true;
            }

            public function createFromOrderPayment(Request $request, Model $order, ?string $token = null): ?Model
            {
                return null;
            }
        });

        $user = User::query()->create(['name' => 'User', 'email' => 'user@example.com']);
        $card = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'card-token',
            'last4' => '4242',
            'brand' => 'Visa',
            'is_default' => true,
        ]);
        $payload = [
            'idempotency_key' => 'checkout-subscription-failure',
            'currency' => 'RUB',
            'products' => [$this->product()],
            'card_id' => $card->id,
            'subscription' => [
                'products' => [$this->product('Premium recurrent', 990)],
                'interval' => 'Month',
                'period' => 1,
            ],
        ];

        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'subscription_creation_failed')
            ->assertJsonPath('payment_succeeded', true);
        $this->actingAs($user)
            ->postJson('/payhub/checkout/orders', $payload)
            ->assertStatus(409);

        $this->assertSame(1, $cloudPayments->charges);
        $this->assertSame('paid', Order::query()->firstOrFail()->status);
        $this->assertNotNull(Order::query()->firstOrFail()->transaction_id);
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
                'idempotency_key' => 'checkout-failed-cloud-card',
                'currency' => 'RUB',
                'products' => [$this->product()],
                'card_id' => $card->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Payment declined');

        $this->assertSame('pending', Order::query()->firstOrFail()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * @return array{name: string, price: float, quantity: float, unit: string}
     */
    private function product(
        string $name = 'Premium',
        float $price = 990,
        float $quantity = 1,
        string $unit = 'payment',
    ): array {
        return compact('name', 'price', 'quantity', 'unit');
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
            public function chargeByToken(
                Model $card,
                Model $order,
                string $description,
                string $email,
                string $ipAddress,
                ?string $requestId = null,
            ): array
            {
                return $requestId ? $this->response : ['Success' => false];
            }
        });
    }
}
