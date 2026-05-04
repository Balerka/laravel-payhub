<?php

namespace Balerka\LaravelReactPayments\Tests\Feature;

use Balerka\LaravelReactPayments\Models\Card;
use Balerka\LaravelReactPayments\Tests\Fixtures\User;
use Balerka\LaravelReactPayments\Tests\TestCase;

class CardsControllerTest extends TestCase
{
    public function test_card_data_returns_current_users_cards(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $otherUser = User::query()->create(['name' => 'Other']);

        Card::query()->create([
            'user_id' => $user->id,
            'token' => 'tok_user',
            'last4' => '4242',
            'brand' => 'visa',
            'is_default' => true,
        ]);

        Card::query()->create([
            'user_id' => $otherUser->id,
            'token' => 'tok_other',
            'last4' => '1111',
            'brand' => 'mastercard',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/cards/data')
            ->assertOk()
            ->assertJsonPath('cards.0.last4', '4242')
            ->assertJsonCount(1, 'cards');
    }

    public function test_user_can_set_default_card(): void
    {
        $user = User::query()->create(['name' => 'User']);
        $firstCard = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'tok_first',
            'last4' => '4242',
            'brand' => 'visa',
            'is_default' => true,
        ]);
        $secondCard = Card::query()->create([
            'user_id' => $user->id,
            'token' => 'tok_second',
            'last4' => '5555',
            'brand' => 'visa',
            'is_default' => false,
        ]);

        $this->actingAs($user)
            ->put('/cards/default', ['card_id' => $secondCard->id])
            ->assertRedirect();

        $this->assertFalse($firstCard->refresh()->is_default);
        $this->assertTrue($secondCard->refresh()->is_default);
    }
}
