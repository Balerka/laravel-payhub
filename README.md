# Laravel Payhub

Reusable payment foundation for Laravel applications.

It provides:

- Laravel models for cards, orders, transactions, and subscriptions.
- Migrations and configurable table names.
- Auth-protected JSON endpoints for custom frontends.
- Embeddable React components for checkout and payment card management.
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

## Frontend Components

Payhub does not register checkout or cards page routes. Publish the React assets and embed the exported components in your own pages:

```bash
composer require inertiajs/inertia-laravel
php artisan vendor:publish --tag=payhub-react
```

The React files publish to:

```text
resources/js/pages/payhub
```

Example:

```tsx
import { Checkout, PaymentCards, PayhubRefunds, PayhubSubscriptions } from '@/pages/payhub'
```

Basic usage:

```tsx
<Checkout amount={990} currency="RUB" description="Premium" locale="ru" />
<PaymentCards locale="ru" />
<PayhubSubscriptions locale="ru" />
<PayhubRefunds locale="ru" />
```

Payhub does not manage products or catalogs. Your application owns product selection and passes payment details into `Checkout`:

- `amount` - required payment amount
- `currency` - optional ISO currency code, defaults to `PAYHUB_CURRENCY`
- `description` - optional text sent to the gateway
- `receipt` - optional full receipt object with `items`, `email`, `amounts`, `currency`, `description`, and any gateway-specific receipt data
- `items` - shorthand for `receipt.items` if you do not need to pass the full receipt object

Payhub ships with embedded English and Russian dictionaries inside the published React files, so the component can pick its own translations from `locale` without touching host project files.

## Translations

If the host project wants to reuse the same strings in its own i18n system, publish the standalone dictionaries too:

```bash
php artisan vendor:publish --tag=payhub-locales
```

This creates:

```text
resources/js/locales/en/payhub.json
resources/js/locales/ru/payhub.json
```

Payhub does not edit host project files. The host app can either:

- let the components localize themselves from `locale`
- import `payhub.json` directly and pass `messages` overrides when needed
- load `payhub.json` into its own i18n system if it already has one
- do nothing and use the built-in English fallback messages

Checkout example:

```tsx
import { Checkout } from '@/pages/payhub'

export default function CheckoutPage() {
  return (
    <Checkout
      amount={990}
      currency="RUB"
      description="Premium"
      receipt={{
        email: 'customer@example.com',
        currency: 'RUB',
        description: 'Premium',
        amounts: { electronic: 990 },
        items: [
          { label: 'Premium', price: 590, quantity: 1, amount: 590 },
          { label: 'Boost', price: 400, quantity: 1, amount: 400 },
        ],
      }}
      locale="ru"
    />
  )
}
```

Override example:

```tsx
import { Checkout } from '@/pages/payhub'
import ruPayhub from '@/locales/ru/payhub.json'

<Checkout
  locale="ru"
  messages={{
    ...ruPayhub,
    checkout: {
      ...ruPayhub.checkout,
      pay: 'Купить',
    },
  }}
/>
```

## Routes

Routes are registered with the `payhub.` name prefix and use `/payhub` as the default URL prefix:

- `GET /payhub/cards/data` returns cards as JSON.
- `GET /payhub/checkout/data` returns gateway metadata and saved cards as JSON.
- `POST /payhub/checkout/orders` creates a pending order from `amount`, `currency`, `description`, and optional `receipt`. Payhub stores the full receipt object on the order. When `card_id` is passed with the `cloud_payments` gateway, Payhub charges that saved card token through CloudPayments.
- `DELETE /payhub/checkout/orders/{order}` cancels a pending order.
- `PUT /payhub/cards/default` sets the default card.
- `DELETE /payhub/cards/{card}` deletes a card owned by the current user.
- `GET /payhub/subscriptions/data` returns subscriptions owned by the current user.
- `POST /payhub/subscriptions/cancel` cancels a current user's CloudPayments subscription.
- `GET /payhub/refunds/data` returns refundable payment transactions owned by the current user.
- `POST /payhub/refunds/refund` refunds or voids a current user's CloudPayments payment transaction.
- `POST /payhub/payments/test/pay` creates a local test payment when `payhub.test_mode=true`.

For checkout, cards, subscriptions, and refunds UI, publish the React assets and embed `Checkout`, `PaymentCards`, `PayhubSubscriptions`, or `PayhubRefunds` in any application page. Payhub does not register page routes for these components.
For a custom frontend, call the JSON endpoints directly and send `Accept: application/json` when you want JSON responses from mutation endpoints.

## Configuration

`config/payhub.php` controls route middleware, table names, user model, currency, test mode, and gateway metadata.

Set checkout currency with:

```dotenv
PAYHUB_CURRENCY=RUB
```

Select the active gateway with:

```dotenv
PAYHUB_GATEWAY=test
```

The published checkout component supports the `test` gateway and CloudPayments widget flow.
When the current user has saved cards, the checkout component lists them, selects the default card, and sends the selected `card_id` for saved-card payment. Users can still choose a new-card payment, which opens the CloudPayments widget.

For CloudPayments:

```dotenv
PAYHUB_GATEWAY=cloud_payments
CP_PUBLIC_ID=pk_...
CP_SECRET=...
```

Configure CloudPayments callbacks to:

```text
POST /payhub/payments/cloud-payments/check
POST /payhub/payments/cloud-payments/pay
POST /payhub/payments/cloud-payments/fail
```

The callbacks are protected with the CloudPayments `Content-HMAC` signature.

To reuse the table names from an existing app, set:

```dotenv
PAYHUB_CARDS_TABLE=cards
PAYHUB_ORDERS_TABLE=orders
PAYHUB_TRANSACTIONS_TABLE=transactions
PAYHUB_SUBSCRIPTIONS_TABLE=subscriptions
```

Only `PAYHUB_*` environment variables are supported.
