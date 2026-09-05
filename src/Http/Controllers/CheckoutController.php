<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Support\CheckoutManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController
{
    public function __construct(
        private readonly CheckoutManager $checkout,
    ) {}

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->checkout->dataPayload($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.name' => ['required', 'string', 'max:255'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
            'products.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'products.*.unit' => ['nullable', 'string', 'max:64'],
            'products.*.vat' => ['nullable'],
            'products.*.method' => ['nullable', 'integer'],
            'products.*.object' => ['nullable', 'integer'],
            'receipt' => ['nullable', 'array'],
            'receipt.email' => ['nullable', 'email'],
            'receipt.amounts' => ['nullable', 'array'],
            'receipt.amounts.electronic' => ['nullable', 'numeric', 'min:0.01'],
            'receipt.amounts.advance_payment' => ['nullable', 'numeric', 'min:0'],
            'receipt.amounts.credit' => ['nullable', 'numeric', 'min:0'],
            'receipt.amounts.provision' => ['nullable', 'numeric', 'min:0'],
            'receipt.items' => ['nullable', 'array', 'min:1'],
            'receipt.items.*.label' => ['required_with:receipt.items', 'string', 'max:255'],
            'receipt.items.*.price' => ['required_with:receipt.items', 'numeric', 'min:0'],
            'receipt.items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'receipt.items.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'receipt.items.*.vat' => ['nullable'],
            'receipt.items.*.method' => ['nullable', 'integer'],
            'receipt.items.*.object' => ['nullable', 'integer'],
            'receipt.items.*.measurement_unit' => ['nullable', 'string', 'max:64'],
            'receipt.items.*.measurementUnit' => ['nullable', 'string', 'max:64'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.label' => ['required_with:items', 'string', 'max:255'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.vat' => ['nullable'],
            'items.*.method' => ['nullable', 'integer'],
            'items.*.object' => ['nullable', 'integer'],
            'items.*.measurement_unit' => ['nullable', 'string', 'max:64'],
            'items.*.measurementUnit' => ['nullable', 'string', 'max:64'],
            'subscription' => ['nullable', 'array'],
            'subscription.enabled' => ['nullable', 'boolean'],
            'subscription.currency' => ['nullable', 'string', 'size:3'],
            'subscription.description' => ['nullable', 'string', 'max:255'],
            'subscription.products' => ['required_with:subscription', 'array', 'min:1'],
            'subscription.products.*.name' => ['required_with:subscription.products', 'string', 'max:255'],
            'subscription.products.*.price' => ['required_with:subscription.products', 'numeric', 'min:0'],
            'subscription.products.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'subscription.products.*.unit' => ['nullable', 'string', 'max:64'],
            'subscription.products.*.vat' => ['nullable'],
            'subscription.products.*.method' => ['nullable', 'integer'],
            'subscription.products.*.object' => ['nullable', 'integer'],
            'subscription.interval' => ['required_with:subscription', 'string', 'in:Day,Week,Month'],
            'subscription.period' => ['required_with:subscription', 'integer', 'min:1'],
            'subscription.start_in' => ['nullable', 'string', 'regex:/^\d+\s+(Minute|Hour|Day|Week|Month|Year)s?$/i'],
            'subscription.metadata' => ['nullable', 'array'],
            'subscription.params' => ['nullable', 'array'],
            'card_id' => ['nullable', 'integer'],
        ]);

        return $this->checkout->store($request, $data);
    }

    public function destroy(Request $request, string|int $order): JsonResponse|RedirectResponse
    {
        return $this->checkout->destroy($request, $order);
    }
}
