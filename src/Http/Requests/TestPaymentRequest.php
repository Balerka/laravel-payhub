<?php

namespace Balerka\LaravelPayhub\Http\Requests;

use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Product;
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
            'amount' => ['required_without:product_id', 'numeric', 'min:0.01'],
            'product_id' => ['nullable', 'integer', Rule::exists((new Product)->getTable(), 'id')],
            'order_id' => ['nullable', 'integer', Rule::exists((new Order)->getTable(), 'id')],
            'source' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'boolean'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'card_token' => ['nullable', 'string', 'max:255'],
            'card_last4' => ['nullable', 'digits:4'],
            'card_bank' => ['nullable', 'string', 'max:255'],
            'card_brand' => ['nullable', 'string', 'max:255'],
            'subscription_id' => ['nullable', 'string', 'max:255'],
            'next_transaction_at' => ['nullable', 'date'],
        ];
    }
}
