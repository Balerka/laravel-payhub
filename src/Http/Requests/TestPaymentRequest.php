<?php

namespace Balerka\LaravelPayhub\Http\Requests;

use Balerka\LaravelPayhub\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (bool) config('payhub.test_mode');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'array'],
            'receipt.email' => ['nullable', 'email'],
            'receipt.amounts' => ['nullable', 'array'],
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
            'order_id' => ['nullable', 'integer', Rule::exists((new Order)->getTable(), 'id')],
            'source' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'boolean'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'card_token' => ['nullable', 'string', 'max:255'],
            'card_last4' => ['nullable', 'digits:4'],
            'card_bank' => ['nullable', 'string', 'max:255'],
            'card_brand' => ['nullable', 'string', 'max:255'],
            'subscription_id' => ['nullable', 'string', 'max:255'],
            'interval' => ['nullable', 'string', 'max:255'],
            'period' => ['nullable', 'integer', 'min:1'],
            'next_transaction_at' => ['nullable', 'date'],
        ];
    }
}
