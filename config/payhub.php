<?php

return [
    'route_prefix' => env('PAYHUB_ROUTE_PREFIX', 'payhub'),

    'route_middleware' => ['web', 'auth', 'verified'],

    'api_middleware' => ['web', 'auth', 'verified'],

    'user_model' => env('PAYHUB_USER_MODEL', 'App\\Models\\User'),

    'models' => [
        'card' => \Balerka\LaravelPayhub\Models\Card::class,
        'order' => \Balerka\LaravelPayhub\Models\Order::class,
        'transaction' => \Balerka\LaravelPayhub\Models\Transaction::class,
        'subscription' => \Balerka\LaravelPayhub\Models\Subscription::class,
    ],

    'currency' => env('PAYHUB_CURRENCY', env('APP_CURRENCY', 'RUB')),

    'test_mode' => filter_var(env('PAYHUB_TEST_MODE', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOL),

    'gateway' => env('PAYHUB_GATEWAY', 'test'),

    'tables' => [
        'cards' => env('PAYHUB_CARDS_TABLE', 'payhub_cards'),
        'orders' => env('PAYHUB_ORDERS_TABLE', 'payhub_orders'),
        'transactions' => env('PAYHUB_TRANSACTIONS_TABLE', 'payhub_transactions'),
        'subscriptions' => env('PAYHUB_SUBSCRIPTIONS_TABLE', 'payhub_subscriptions'),
    ],

    'gateways' => [
        'test' => [
            'enabled' => env('PAYHUB_TEST_MODE', env('APP_ENV') === 'local'),
            'commission' => (float) env('PAYHUB_TEST_COMMISSION', 0),
            'vat' => 1 + ((float) env('PAYHUB_TEST_VAT', 0) / 100),
        ],
        'cloud_payments' => [
            'route_prefix' => env('PAYHUB_CLOUD_PAYMENTS_ROUTE_PREFIX', 'api/cloudpayments'),
            'middleware' => [],
            'api_url' => env('CP_API_URL', 'https://api.cloudpayments.ru'),
            'public_id' => env('CP_PUBLIC_ID'),
            'secret' => env('CP_SECRET'),
            'commission' => (float) env('CP_COMMISSION', 3.9) / 100,
            'vat' => 1 + ((float) env('CP_VAT', 22) / 100),
        ],
    ],
];
