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
        'user_id',
        'product_id',
        'status',
        'transaction_id',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('payhub.user_model'));
    }
}
