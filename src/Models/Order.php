<?php

namespace Balerka\LaravelPayhub\Models;

use Balerka\LaravelPayhub\Models\Concerns\UsesPaymentTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use UsesPaymentTable;

    protected string $paymentTableKey = 'orders';

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'transaction_id',
        'amount',
        'currency',
        'receipt',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'receipt' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('payhub.user_model'));
    }
}
