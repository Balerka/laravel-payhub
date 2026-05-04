<?php

return [
    'frontend' => env('PAYHUB_FRONTEND', 'headless'),

    'route_prefix' => env('PAYHUB_ROUTE_PREFIX', env('PAYMENTS_ROUTE_PREFIX', 'payhub')),

    'route_middleware' => ['web', 'auth', 'verified'],

    'api_middleware' => ['web', 'auth', 'verified'],

    'cards_page' => 'payhub/pages/cards',

    'user_model' => env('PAYHUB_USER_MODEL', env('PAYMENTS_USER_MODEL', 'App\\Models\\User')),

    'test_mode' => filter_var(env('PAYHUB_TEST_MODE', env('PAYMENTS_TEST_MODE', env('APP_ENV') === 'local')), FILTER_VALIDATE_BOOL),

    'tables' => [
        'cards' => env('PAYHUB_CARDS_TABLE', env('PAYMENTS_CARDS_TABLE', 'payment_cards')),
        'products' => env('PAYHUB_PRODUCTS_TABLE', env('PAYMENTS_PRODUCTS_TABLE', 'payment_products')),
        'orders' => env('PAYHUB_ORDERS_TABLE', env('PAYMENTS_ORDERS_TABLE', 'payment_orders')),
        'transactions' => env('PAYHUB_TRANSACTIONS_TABLE', env('PAYMENTS_TRANSACTIONS_TABLE', 'payment_transactions')),
        'subscriptions' => env('PAYHUB_SUBSCRIPTIONS_TABLE', env('PAYMENTS_SUBSCRIPTIONS_TABLE', 'payment_subscriptions')),
    ],

    'gateways' => [
        'test' => [
            'enabled' => env('PAYHUB_TEST_MODE', env('PAYMENTS_TEST_MODE', env('APP_ENV') === 'local')),
            'commission' => (float) env('PAYHUB_TEST_COMMISSION', env('PAYMENTS_TEST_COMMISSION', 0)),
            'vat' => 1 + ((float) env('PAYHUB_TEST_VAT', env('PAYMENTS_TEST_VAT', 0)) / 100),
        ],
        'cloud_payments' => [
            'public_id' => env('CP_PUBLIC_ID'),
            'secret' => env('CP_SECRET'),
            'commission' => (float) env('CP_COMMISSION', 3.9) / 100,
            'vat' => 1 + ((float) env('CP_VAT', 22) / 100),
        ],
    ],
];
