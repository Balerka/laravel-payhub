<?php

namespace Balerka\LaravelPayhub\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class CheckoutManager
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
        private readonly SubscriptionManager $subscriptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dataPayload(Request $request): array
    {
        return [
            'currencyCode' => config('payhub.currency', config('app.currency', 'RUB')),
            'gateway' => $this->gatewayPayload(),
            'cards' => $this->cardsPayload($request),
            'selectedCardId' => $this->selectedCardId($request),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(Request $request, array $data): JsonResponse
    {
        if (! GatewayResolver::enabled($this->gatewayCode())) {
            return response()->json([
                'ok' => false,
                'error' => __('Payment disabled'),
                'error_code' => 'payment_disabled',
            ], 403);
        }

        $card = $this->selectedCard($request, $data['card_id'] ?? null);
        $currency = strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB'))));
        $productItems = $this->receiptItemsFromProducts($data['products']);
        $amount = $this->receiptItemsAmount($productItems);
        $description = filled($data['description'] ?? null)
            ? (string) $data['description']
            : $this->receiptItemsDescription($productItems);
        $receipt = $this->receipt($request, $data, $productItems, $amount, $currency, $description);
        $receipt['_payhub'] = [
            'card_id' => $card?->getKey(),
        ];

        $order = $this->resolveCheckoutOrder([
            'idempotency_key' => (string) $data['idempotency_key'],
            'user_id' => $request->user()->id,
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => $receipt,
            'status' => 'pending',
        ]);

        if (! $order) {
            return $this->checkoutConflictResponse();
        }

        if (in_array($order->status, ['paid', 'authorized'], true) && $order->transaction_id !== null) {
            $transaction = $this->attachedTransaction($order);

            if (! $transaction) {
                return $this->checkoutConflictResponse();
            }

            if ($card) {
                return $this->completeSavedCardPayment($request, $order, $transaction, $card);
            }

            return $this->savedCardResponse($order, $transaction);
        }

        if ($order->status !== 'pending') {
            return $this->checkoutConflictResponse();
        }

        if ($card && $this->gatewayCode() === 'cloud_payments') {
            return $this->chargeSavedCloudPaymentsCard($request, $order, $card, $description);
        }

        if ($card && $this->gatewayCode() === 'test') {
            return $this->chargeSavedTestCard($order, $card);
        }

        return response()->json([
            'ok' => true,
            'flow' => $this->gatewayCode() === 'cloud_payments' ? 'cloudpayments' : 'test',
            'order' => $this->orderPayload($order),
            'payment' => $this->paymentPayload($request, $order, $description),
        ]);
    }

    public function destroy(Request $request, string|int $order): JsonResponse|RedirectResponse
    {
        $order = $this->findOrder($order);

        if (! $order) {
            return $this->emptyResponse($request);
        }

        if ((int) $order->user_id !== (int) $request->user()->id) {
            return $this->emptyResponse($request);
        }

        if ($order->status === 'pending' && $order->transaction_id === null) {
            $order->delete();
        }

        return $this->emptyResponse($request);
    }

    private function chargeSavedCloudPaymentsCard(Request $request, Model $order, Model $card, string $description): JsonResponse
    {
        if ($order->transaction_id !== null) {
            $transaction = $this->attachedTransaction($order, 'cloud_payments');

            return $transaction
                ? $this->completeSavedCardPayment($request, $order, $transaction, $card)
                : $this->checkoutConflictResponse();
        }

        try {
            $response = $this->cloudPayments->chargeByToken(
                $card,
                $order,
                $description,
                (string) ($request->user()->email ?? ''),
                $request->ip() ?? '',
                $this->paymentRequestId($order),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'ok' => false,
                'error' => __('Unable to charge saved card.'),
            ], 422);
        }

        if (($response['Success'] ?? false) !== true) {
            return response()->json([
                'ok' => false,
                'error' => $this->cloudPaymentsErrorMessage($response),
            ], 422);
        }

        $model = is_array($response['Model'] ?? null) ? $response['Model'] : [];
        $transactionId = $model['TransactionId'] ?? $response['TransactionId'] ?? null;

        if (! is_scalar($transactionId) || (string) $transactionId === '') {
            return $this->checkoutConflictResponse();
        }

        $vat = $model['VatAboveTotalFee'] ?? $response['VatAboveTotalFee'] ?? null;
        $transaction = $this->recordTransaction(
            $order,
            (string) $transactionId,
            (float) ($model['TotalFee'] ?? $response['TotalFee'] ?? 0),
            is_numeric($vat) ? (float) $vat : null,
            'cloud_payments',
        );

        return $transaction
            ? $this->completeSavedCardPayment($request, $order->refresh(), $transaction, $card)
            : $this->checkoutConflictResponse();
    }

    private function chargeSavedTestCard(Model $order, Model $card): JsonResponse
    {
        $fee = GatewayFees::fee((float) $order->amount, 'test');
        $transaction = $order->transaction_id === null
            ? $this->recordTransaction(
                $order,
                'test-saved-card-'.$order->id.'-'.$card->id,
                $fee,
                GatewayFees::vat($fee, 'test'),
                'test',
            )
            : $this->attachedTransaction($order, 'test');

        if (! $transaction) {
            return $this->checkoutConflictResponse();
        }

        return $this->completeSavedCardPayment(
            Request::create('', 'POST'),
            $order->refresh(),
            $transaction,
            $card,
        );
    }

    private function completeSavedCardPayment(Request $request, Model $order, Model $transaction, Model $card): JsonResponse
    {
        if ($order->status === 'pending') {
            $order->update(['status' => 'paid']);
        }

        if (
            $this->subscriptions->requiresSubscription($order)
            && ! $this->subscriptions->createFromOrderPayment($request, $order, (string) $card->token)
        ) {
            return response()->json([
                'ok' => false,
                'error' => __('Payment succeeded, but recurring subscription could not be created.'),
                'error_code' => 'subscription_creation_failed',
                'payment_succeeded' => true,
                'order' => $this->orderPayload($order->refresh()),
            ], 409);
        }

        return $this->savedCardResponse($order->refresh(), $transaction);
    }

    private function savedCardResponse(Model $order, Model $transaction): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'flow' => 'saved_card',
            'order' => $this->orderPayload($order),
            'transaction' => [
                'id' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCheckoutOrder(array $attributes): ?Model
    {
        $orderModel = PayhubModels::order();
        $idempotencyKey = (string) $attributes['idempotency_key'];
        $order = $orderModel::query()->createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            $attributes,
        );

        if (
            (int) $order->user_id !== (int) $attributes['user_id']
            || ! $this->amountsMatch((float) $order->amount, (float) $attributes['amount'])
            || strtoupper((string) $order->currency) !== (string) $attributes['currency']
            || $this->storedReceipt($order) != $attributes['receipt']
        ) {
            return null;
        }

        return $order;
    }

    private function recordTransaction(
        Model $order,
        string $transactionId,
        float $fee,
        ?float $vat,
        string $gateway,
    ): ?Model {
        return $order->getConnection()->transaction(function () use ($order, $transactionId, $fee, $vat, $gateway): ?Model {
            $lockedOrder = $order->newQuery()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return null;
            }

            $transactionModel = PayhubModels::transaction();

            if ($lockedOrder->transaction_id !== null) {
                $transaction = $transactionModel::query()
                    ->whereKey($lockedOrder->transaction_id)
                    ->lockForUpdate()
                    ->first();

                return $transaction && $this->transactionMatchesOrder($transaction, $lockedOrder, $gateway)
                    ? $transaction
                    : null;
            }

            $transaction = $transactionModel::query()->firstOrCreate(
                ['transaction_id' => $transactionId],
                [
                    'user_id' => $lockedOrder->user_id,
                    'amount' => (float) $lockedOrder->amount,
                    'fee' => $fee,
                    'vat' => $vat,
                    'status' => true,
                    'gateway' => $gateway,
                ],
            );
            $transaction = $transactionModel::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->transactionMatchesOrder($transaction, $lockedOrder, $gateway)) {
                return null;
            }

            $usedByAnotherOrder = $order->newQuery()
                ->where('transaction_id', $transaction->getKey())
                ->whereKeyNot($lockedOrder->getKey())
                ->exists();

            if ($usedByAnotherOrder) {
                return null;
            }

            $updates = [];

            if ((float) $transaction->fee === 0.0 && $fee > 0) {
                $updates['fee'] = $fee;
            }

            if ($transaction->vat === null && $vat !== null) {
                $updates['vat'] = $vat;
            }

            if ($updates !== []) {
                $transaction->update($updates);
            }

            $lockedOrder->update(['transaction_id' => $transaction->getKey()]);

            return $transaction;
        });
    }

    private function attachedTransaction(Model $order, ?string $expectedGateway = null): ?Model
    {
        if ($order->transaction_id === null) {
            return null;
        }

        $transactionModel = PayhubModels::transaction();
        $transaction = $transactionModel::query()->whereKey($order->transaction_id)->first();

        if (! $transaction) {
            return null;
        }

        $gateway = $expectedGateway ?? GatewayResolver::forTransaction($transaction->gateway);

        return $this->transactionMatchesOrder($transaction, $order, $gateway) ? $transaction : null;
    }

    private function transactionMatchesOrder(Model $transaction, Model $order, string $gateway): bool
    {
        return (int) $transaction->user_id === (int) $order->user_id
            && (bool) $transaction->status
            && $this->amountsMatch((float) $transaction->amount, (float) $order->amount)
            && GatewayResolver::forTransaction($transaction->gateway) === GatewayResolver::forTransaction($gateway);
    }

    private function amountsMatch(float $first, float $second): bool
    {
        return abs($first - $second) < 0.01;
    }

    private function paymentRequestId(Model $order): string
    {
        return hash('sha256', implode('|', [
            (string) config('app.key'),
            $order->getConnectionName() ?? '',
            $order->getTable(),
            (string) $order->getKey(),
            'saved-card-charge',
        ]));
    }

    private function checkoutConflictResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => __('Checkout request conflicts with an existing payment.'),
            'error_code' => 'checkout_conflict',
        ], 409);
    }

    private function selectedCard(Request $request, mixed $cardId): ?Model
    {
        if (! $cardId) {
            return null;
        }

        $cardModel = PayhubModels::card();

        return $cardModel::query()
            ->where('user_id', $request->user()->id)
            ->whereKey((int) $cardId)
            ->firstOrFail();
    }

    private function selectedCardId(Request $request): ?int
    {
        $cardModel = PayhubModels::card();

        return $cardModel::query()
            ->where('user_id', $request->user()->id)
            ->where('is_default', true)
            ->value('id');
    }

    /**
     * @return array<int, array{id: int, bank: string|null, brand: string, last4: string, is_default: bool}>
     */
    private function cardsPayload(Request $request): array
    {
        $cardModel = PayhubModels::card();

        return $cardModel::query()
            ->where('user_id', $request->user()->id)
            ->get(['id', 'bank', 'brand', 'last4', 'is_default'])
            ->map(fn (Model $card): array => [
                'id' => $card->id,
                'bank' => $card->bank,
                'brand' => $card->brand,
                'last4' => $card->last4,
                'is_default' => (bool) $card->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{code: string, enabled: bool, testMode: bool, publicId: string|null}
     */
    private function gatewayPayload(): array
    {
        $gatewayCode = $this->gatewayCode();

        return [
            'code' => $gatewayCode,
            'enabled' => GatewayResolver::enabled($gatewayCode),
            'testMode' => $gatewayCode === 'test',
            'publicId' => $gatewayCode === 'cloud_payments'
                ? config('payhub.gateways.cloud_payments.public_id')
                : null,
        ];
    }

    private function gatewayCode(): string
    {
        return GatewayResolver::active();
    }

    /**
     * @return array{id: int, amount: float, currency: string, status: string}
     */
    private function orderPayload(Model $order): array
    {
        return [
            'id' => $order->id,
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'status' => $order->status,
        ];
    }

    /**
     * @return array{gateway: string, publicId: string|null, description: string, quantity: float, price: float, amount: float, currency: string, accountId: int, orderId: int, email: string, unit: string, receipt: array<string, mixed>, items: array<int, array<string, mixed>>, subscription: array<string, mixed>|null}
     */
    private function paymentPayload(Request $request, Model $order, string $description): array
    {
        $receipt = $this->storedReceipt($order);
        unset($receipt['_payhub']);

        return [
            'gateway' => $this->gatewayCode(),
            'publicId' => $this->gatewayCode() === 'cloud_payments'
                ? config('payhub.gateways.cloud_payments.public_id')
                : null,
            'description' => $description,
            'quantity' => (float) data_get($receipt, 'items.0.quantity', 1),
            'price' => (float) data_get($receipt, 'items.0.price', $order->amount),
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'accountId' => (int) $request->user()->id,
            'orderId' => (int) $order->id,
            'email' => (string) ($request->user()->email ?? ''),
            'unit' => (string) data_get($receipt, 'items.0.measurementUnit', 'payment'),
            'receipt' => $receipt,
            'items' => $receipt['items'] ?? [],
            'subscription' => is_array($receipt['subscription'] ?? null) ? $receipt['subscription'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{label: string, price: float, quantity: float, amount: float, vat: mixed, method: int, object: int, measurementUnit: string}>  $productItems
     * @return array{items: array<int, array{label: string, price: float, quantity: float, amount: float, vat: mixed, method: int, object: int, measurementUnit: string}>, email: string, amounts: array<string, float>, currency: string, description: string, subscription: array<string, mixed>|null}
     */
    private function receipt(
        Request $request,
        array $data,
        array $productItems,
        float $amount,
        string $currency,
        string $description,
    ): array
    {
        $receipt = is_array($data['receipt'] ?? null) ? $data['receipt'] : [];
        $receiptItems = is_array($receipt['items'] ?? null) ? $receipt['items'] : ($data['items'] ?? []);

        $items = collect($receiptItems)
            ->map(fn (array $item): array => $this->normalizeReceiptItem($item))
            ->values()
            ->all();

        $items = $items === [] ? $productItems : $items;

        $itemsAmount = round(array_sum(array_column($items, 'amount')), 2);

        abort_if($itemsAmount !== round($amount, 2), 422, 'Receipt items amount must equal payment amount.');

        $amounts = is_array($receipt['amounts'] ?? null) ? $receipt['amounts'] : [];
        $amounts['electronic'] = (float) ($amounts['electronic'] ?? $amount);

        return [
            'items' => $items,
            'email' => (string) ($receipt['email'] ?? $request->user()->email ?? ''),
            'amounts' => $amounts,
            'currency' => $currency,
            'description' => $description,
            'subscription' => $this->subscriptionData($data['subscription'] ?? null),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array{label: string, price: float, quantity: float, amount: float, vat: mixed, method: int, object: int, measurementUnit: string}>
     */
    private function receiptItemsFromProducts(array $products): array
    {
        return collect($products)
            ->map(fn (array $product): array => $this->normalizeReceiptItem([
                'label' => $product['name'],
                'price' => $product['price'],
                'quantity' => $product['quantity'] ?? 1,
                'vat' => $product['vat'] ?? null,
                'method' => $product['method'] ?? 1,
                'object' => $product['object'] ?? 4,
                'measurementUnit' => $product['unit'] ?? 'payment',
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{amount: float}>  $items
     */
    private function receiptItemsAmount(array $items): float
    {
        $amount = round(array_sum(array_column($items, 'amount')), 2);

        abort_if($amount < 0.01, 422, __('Payment amount must be at least 0.01.'));

        return $amount;
    }

    /**
     * @param  array<int, array{label: string}>  $items
     */
    private function receiptItemsDescription(array $items): string
    {
        return mb_substr(implode(', ', array_column($items, 'label')), 0, 255);
    }

    /**
     * @param  array<string, mixed>|null  $subscription
     * @return array<string, mixed>|null
     */
    private function subscriptionData(?array $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        $items = $this->receiptItemsFromProducts($subscription['products'] ?? []);
        unset($subscription['products']);

        $subscription['amount'] = $this->receiptItemsAmount($items);
        $subscription['description'] = filled($subscription['description'] ?? null)
            ? (string) $subscription['description']
            : $this->receiptItemsDescription($items);
        $subscription['quantity'] = (float) $items[0]['quantity'];
        $subscription['unit'] = (string) $items[0]['measurementUnit'];
        $subscription['items'] = $items;

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{label: string, price: float, quantity: float, amount: float, vat: mixed, method: int, object: int, measurementUnit: string}
     */
    private function normalizeReceiptItem(array $item): array
    {
        $quantity = (float) ($item['quantity'] ?? 1);
        $price = (float) $item['price'];
        $amount = (float) ($item['amount'] ?? round($price * $quantity, 2));

        return [
            'label' => (string) $item['label'],
            'price' => $price,
            'quantity' => $quantity,
            'amount' => $amount,
            'vat' => $item['vat'] ?? null,
            'method' => (int) ($item['method'] ?? 1),
            'object' => (int) ($item['object'] ?? 4),
            'measurementUnit' => (string) ($item['measurement_unit'] ?? $item['measurementUnit'] ?? 'payment'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedReceipt(Model $order): array
    {
        $receipt = $order->receipt;

        return is_array($receipt) ? $receipt : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function cloudPaymentsErrorMessage(array $response): string
    {
        $model = is_array($response['Model'] ?? null) ? $response['Model'] : [];
        $message = $model['CardHolderMessage'] ?? $response['Message'] ?? null;

        return is_string($message) && $message !== ''
            ? $message
            : __('Unable to charge saved card.');
    }

    private function findOrder(string|int $id): ?Model
    {
        $orderModel = PayhubModels::order();

        return $orderModel::query()->whereKey($id)->first();
    }

    private function emptyResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
