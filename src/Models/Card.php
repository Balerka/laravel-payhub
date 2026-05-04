<?php

namespace Balerka\LaravelPayhub\Models;

use Balerka\LaravelPayhub\Models\Concerns\UsesPaymentTable;
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
        return $this->belongsTo(config('payhub.user_model'));
    }
}
