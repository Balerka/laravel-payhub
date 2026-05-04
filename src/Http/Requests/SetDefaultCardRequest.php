<?php

namespace Balerka\LaravelPayhub\Http\Requests;

use Balerka\LaravelPayhub\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetDefaultCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'card_id' => [
                'required',
                'integer',
                Rule::exists((new Card)->getTable(), 'id')
                    ->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
            ],
        ];
    }
}
