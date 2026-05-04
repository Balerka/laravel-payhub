<?php

return [
    'route_prefix' => env('PAYMENTS_ROUTE_PREFIX', ''),

    'route_middleware' => ['web', 'auth', 'verified'],

    'api_middleware' => ['web', 'auth', 'verified'],

    'cards_page' => 'payments/pages/cards',

    'user_model' => env('PAYMENTS_USER_MODEL', 'App\\Models\\User'),

    'test_mode' => filter_var(env('PAYMENTS_TEST_MODE', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOL),

    'tables' => [
        'cards' => env('PAYMENTS_CARDS_TABLE', 'payment_cards'),
        'products' => env('PAYMENTS_PRODUCTS_TABLE', 'payment_products'),
        'orders' => env('PAYMENTS_ORDERS_TABLE', 'payment_orders'),
        'transactions' => env('PAYMENTS_TRANSACTIONS_TABLE', 'payment_transactions'),
        'subscriptions' => env('PAYMENTS_SUBSCRIPTIONS_TABLE', 'payment_subscriptions'),
    ],

    'gateways' => [
        'test' => [
            'enabled' => env('PAYMENTS_TEST_MODE', env('APP_ENV') === 'local'),
            'commission' => (float) env('PAYMENTS_TEST_COMMISSION', 0),
            'vat' => 1 + ((float) env('PAYMENTS_TEST_VAT', 0) / 100),
        ],
        'cloud_payments' => [
            'public_id' => env('CP_PUBLIC_ID'),
            'secret' => env('CP_SECRET'),
            'commission' => (float) env('CP_COMMISSION', 3.9) / 100,
            'vat' => 1 + ((float) env('CP_VAT', 22) / 100),
        ],
    ],
];
