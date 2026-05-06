<?php

namespace Balerka\LaravelPayhub\Models;

use Balerka\LaravelPayhub\Models\Concerns\UsesPaymentTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use UsesPaymentTable;

    protected string $paymentTableKey = 'subscriptions';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'amount',
        'currency',
        'description',
        'interval',
        'period',
        'status',
        'next_transaction_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'next_transaction_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('payhub.user_model'));
    }
}
