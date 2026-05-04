# Laravel Payhub

Reusable payment foundation for Laravel applications.

It provides:

- Laravel models for cards, products, orders, transactions, and subscriptions.
- Migrations and configurable table names.
- Auth-protected JSON endpoints for custom frontends.
- Optional React/Inertia card management screen.
- A local test payment endpoint for development.

## Install

```bash
composer require balerka/laravel-payhub
php artisan vendor:publish --tag=payhub-config
php artisan vendor:publish --tag=payhub-migrations
php artisan migrate
```

For local development from this repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-payhub"
    }
  ]
}
```

Then run:

```bash
composer require balerka/laravel-payhub:@dev
```

## Frontend Modes

Laravel Payhub is headless by default:

```dotenv
PAYHUB_FRONTEND=headless
```

Use this mode when you want to connect your own Blade, Vue, React, mobile app, or any other UI to the JSON endpoints.

To use the included React/Inertia screen:

```bash
composer require inertiajs/inertia-laravel
php artisan vendor:publish --tag=payhub-react
```

Then set:

```dotenv
PAYHUB_FRONTEND=react
```

The React files publish to:

```text
resources/js/payhub
```

In an Inertia app, make sure the published page is resolvable by your page loader. With the default Laravel/Inertia setup, publishing to `resources/js/payhub/pages/cards.tsx` pairs with this config value:

```php
'cards_page' => 'payhub/pages/cards',
```

## Routes

Routes are registered with the `payhub.` name prefix and use `/payhub` as the default URL prefix:

- `GET /payhub/cards/data` returns cards as JSON.
- `PUT /payhub/cards/default` sets the default card.
- `DELETE /payhub/cards/{card}` deletes a card owned by the current user.
- `POST /payhub/payments/test/pay` creates a local test payment when `payhub.test_mode=true`.
- `GET /payhub/cards` renders the React/Inertia cards page only when `PAYHUB_FRONTEND=react`.

For a custom frontend, call the JSON endpoints directly and send `Accept: application/json` when you want JSON responses from mutation endpoints.

## Configuration

`config/payhub.php` controls frontend mode, route middleware, page name, table names, user model, test mode, and gateway metadata.

To reuse the table names from an existing app, set:

```dotenv
PAYHUB_CARDS_TABLE=cards
PAYHUB_PRODUCTS_TABLE=products
PAYHUB_ORDERS_TABLE=orders
PAYHUB_TRANSACTIONS_TABLE=transactions
PAYHUB_SUBSCRIPTIONS_TABLE=subscriptions
```

The old `PAYMENTS_*` environment variables are still read as fallbacks where practical, but new projects should use `PAYHUB_*`.
