<?php

namespace Balerka\LaravelReactPayments\Models;

use Balerka\LaravelReactPayments\Models\Concerns\UsesPaymentTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    use UsesPaymentTable;

    protected string $paymentTableKey = 'cards';

    protected $fillable = [
        'user_id',
        'token',
        'last4',
        'bank',
        'brand',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('payments.user_model'));
    }
}
