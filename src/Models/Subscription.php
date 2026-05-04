<?php

namespace Balerka\LaravelReactPayments\Models;

use Balerka\LaravelReactPayments\Models\Concerns\UsesPaymentTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use UsesPaymentTable;

    protected string $paymentTableKey = 'subscriptions';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'product_id',
        'status',
        'next_transaction_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'next_transaction_at' => 'datetime',
        ];
    }

    public function scopeWithProductName(Builder $query): Builder
    {
        $productsTable = (new Product)->getTable();
        $subscriptionsTable = $this->getTable();

        return $query
            ->select("{$subscriptionsTable}.*")
            ->join($productsTable, "{$productsTable}.id", '=', "{$subscriptionsTable}.product_id")
            ->addSelect("{$productsTable}.name");
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('payments.user_model'));
    }
}
