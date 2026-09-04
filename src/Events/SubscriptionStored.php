<?php

namespace Balerka\LaravelPayhub\Events;

use Illuminate\Database\Eloquent\Model;

class SubscriptionStored
{
    public function __construct(
        public readonly Model $subscription,
        public readonly Model $order,
    ) {}
}
