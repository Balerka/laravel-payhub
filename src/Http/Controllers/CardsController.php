<?php

namespace Balerka\LaravelReactPayments\Http\Controllers;

use Balerka\LaravelReactPayments\Http\Requests\SetDefaultCardRequest;
use Balerka\LaravelReactPayments\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardsController
{
    public function index(Request $request): Response
    {
        return Inertia::render(config('payments.cards_page', 'payments/pages/cards'), [
            'cards' => $this->cards($request),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json([
            'cards' => $this->cards($request),
        ]);
    }

    public function setDefault(SetDefaultCardRequest $request): RedirectResponse
    {
        $cardId = (int) $request->validated('card_id');
        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($cardId)
            ->first();

        if (! $card) {
            return back();
        }

        Card::query()
            ->where('user_id', $request->user()->id)
            ->update(['is_default' => false]);

        $card->update(['is_default' => true]);

        return back();
    }

    public function destroy(Request $request, Card $card): RedirectResponse
    {
        if ((int) $card->user_id !== (int) $request->user()->id) {
            return back();
        }

        $wasDefault = (bool) $card->is_default;
        $card->delete();

        if ($wasDefault) {
            Card::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->first()
                ?->update(['is_default' => true]);
        }

        return back();
    }

    /**
     * @return array<int, array{id: int, bank: string|null, brand: string, last4: string, is_default: bool}>
     */
    private function cards(Request $request): array
    {
        return Card::query()
            ->where('user_id', $request->user()->id)
            ->get(['id', 'bank', 'brand', 'last4', 'is_default'])
            ->map(fn (Card $card): array => [
                'id' => $card->id,
                'bank' => $card->bank,
                'brand' => $card->brand,
                'last4' => $card->last4,
                'is_default' => (bool) $card->is_default,
            ])
            ->values()
            ->all();
    }
}
