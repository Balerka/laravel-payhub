<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Http\Requests\SetDefaultCardRequest;
use Balerka\LaravelPayhub\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CardsController
{
    public function data(Request $request): JsonResponse
    {
        return response()->json([
            'cards' => $this->cards($request),
        ]);
    }

    public function setDefault(SetDefaultCardRequest $request): JsonResponse|RedirectResponse
    {
        $cardId = (int) $request->validated('card_id');
        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($cardId)
            ->first();

        if (! $card) {
            return $this->emptyResponse($request);
        }

        Card::query()
            ->where('user_id', $request->user()->id)
            ->update(['is_default' => false]);

        $card->update(['is_default' => true]);

        return $this->emptyResponse($request);
    }

    public function destroy(Request $request, Card $card): JsonResponse|RedirectResponse
    {
        if ((int) $card->user_id !== (int) $request->user()->id) {
            return $this->emptyResponse($request);
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

        return $this->emptyResponse($request);
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

    private function emptyResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
