<?php

namespace App\Models;

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
        'vat',
        'status',
        'gateway',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'vat' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    protected $appends = [
        'income',
    ];

    public function getIncomeAttribute(): float
    {
        return (float) $this->amount - (float) $this->fee - (float) ($this->vat ?? 0);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
