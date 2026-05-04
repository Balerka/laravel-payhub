# Laravel + React Payments

Reusable payment foundation for Laravel applications with React/Inertia frontends.

It provides:

- Laravel models for cards, products, orders, transactions, and subscriptions.
- Migrations and configurable table names.
- Auth-protected card management endpoints.
- A local test payment endpoint for development.
- Publishable React components and hooks for a cards screen.

## Install

```bash
composer require balerka/laravel-react-payments
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan vendor:publish --tag=payments-react
php artisan migrate
```

For local development from this repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-react-payments"
    }
  ]
}
```

Then run:

```bash
composer require balerka/laravel-react-payments:@dev
```

## Routes

Routes are registered with the `payments.` name prefix:

- `GET /cards` renders the Inertia cards page.
- `GET /cards/data` returns cards as JSON.
- `PUT /cards/default` sets the default card.
- `DELETE /cards/{card}` deletes a card owned by the current user.
- `POST /payments/test/pay` creates a local test payment when `payments.test_mode=true`.

## React

The React files publish to:

```text
resources/js/payments
```

They are intentionally dependency-light: React, axios, `@inertiajs/react`, and Tailwind classes.

In an Inertia app, make sure the published page is resolvable by your page loader. With the default Laravel/Inertia setup, publishing to `resources/js/payments/pages/cards.tsx` pairs with this config value:

```php
'cards_page' => 'payments/pages/cards',
```

## Configuration

`config/payments.php` controls route middleware, page name, table names, user model, test mode, and gateway metadata.

To reuse the table names from an existing app, set:

```dotenv
PAYMENTS_CARDS_TABLE=cards
PAYMENTS_PRODUCTS_TABLE=products
PAYMENTS_ORDERS_TABLE=orders
PAYMENTS_TRANSACTIONS_TABLE=transactions
PAYMENTS_SUBSCRIPTIONS_TABLE=subscriptions
```
