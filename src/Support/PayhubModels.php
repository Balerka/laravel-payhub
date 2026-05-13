<?php

namespace Balerka\LaravelPayhub\Support;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Subscription;
use Balerka\LaravelPayhub\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

class PayhubModels
{
    /**
     * @return class-string<Model>
     */
    public static function card(): string
    {
        return self::model('card', Card::class);
    }

    /**
     * @return class-string<Model>
     */
    public static function order(): string
    {
        return self::model('order', Order::class);
    }

    /**
     * @return class-string<Model>
     */
    public static function transaction(): string
    {
        return self::model('transaction', Transaction::class);
    }

    /**
     * @return class-string<Model>
     */
    public static function subscription(): string
    {
        return self::model('subscription', Subscription::class);
    }

    /**
     * @param  class-string<Model>  $fallback
     * @return class-string<Model>
     */
    private static function model(string $key, string $fallback): string
    {
        $model = config("payhub.models.{$key}", $fallback);

        return is_string($model) && is_a($model, Model::class, true)
            ? $model
            : $fallback;
    }
}
