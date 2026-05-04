<?php

namespace Balerka\LaravelPayhub\Tests\Feature;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Tests\Fixtures\User;
use Balerka\LaravelPayhub\Tests\TestCase;

class TestPaymentsControllerTest extends TestCase
{
    public function test_test_payment_creates_transaction_and_card(): void
    {
        $user = User::query()->create(['name' => 'User']);

        $this->actingAs($user)
            ->postJson('/payhub/payments/test/pay', [
                'amount' => 1200,
                'card_token' => 'tok_test',
                'card_last4' => '4242',
                'card_brand' => 'visa',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('card.last4', '4242');

        $this->assertSame(1, Transaction::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, Card::query()->where('user_id', $user->id)->count());
    }
}
