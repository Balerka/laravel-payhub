<?php

namespace Balerka\LaravelPayhub\Models;

use Balerka\LaravelPayhub\Models\Concerns\UsesPaymentTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use UsesPaymentTable;

    protected string $paymentTableKey = 'products';

    protected $fillable = [
        'name',
        'description',
        'type',
        'item',
        'quantity',
        'period',
        'price',
        'recurrent_id',
    ];

    protected $hidden = [
        'description',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function recurrent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrent_id');
    }

    public function recurrentFor(): HasMany
    {
        return $this->hasMany(self::class, 'recurrent_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
