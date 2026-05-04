<?php

namespace Balerka\LaravelPayhub\Models;

use Balerka\LaravelPayhub\Models\Concerns\UsesPaymentTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    use UsesPaymentTable;

    protected string $paymentTableKey = 'transactions';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'amount',
        'fee',
        'status',
        'source',
    ];

    protected $appends = [
        'income',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    public function getIncomeAttribute(): float
    {
        return (float) $this->amount - (float) $this->fee;
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('payhub.user_model'));
    }
}
