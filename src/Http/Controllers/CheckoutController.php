<?php

namespace Balerka\LaravelPayhub\Http\Controllers;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use Balerka\LaravelPayhub\Support\CloudPaymentsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckoutController
{
    public function __construct(
        private readonly CloudPaymentsClient $cloudPayments,
    ) {}

    public function data(Request $request): JsonResponse
    {
        return response()->json([
            'currencyCode' => config('payhub.currency', config('app.currency', 'RUB')),
            'gateway' => $this->gatewayPayload(),
            'cards' => $this->cardsPayload($request),
            'selectedCardId' => $this->selectedCardId($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'array'],
            'receipt.email' => ['nullable', 'email'],
            'receipt.amounts' => ['nullable', 'array'],
            'receipt.amounts.electronic' => ['nullable', 'numeric', 'min:0.01'],
            'receipt.amounts.advance_payment' => ['nullable', 'numeric', 'min:0'],
            'receipt.amounts.credit' => ['nullable', 'numeric', 'min:0'],
            'receipt.amounts.provision' => ['nullable', 'numeric', 'min:0'],
            'receipt.items' => ['nullable', 'array', 'min:1'],
            'receipt.items.*.label' => ['required_with:receipt.items', 'string', 'max:255'],
            'receipt.items.*.price' => ['required_with:receipt.items', 'numeric', 'min:0'],
            'receipt.items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'receipt.items.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'receipt.items.*.vat' => ['nullable'],
            'receipt.items.*.method' => ['nullable', 'integer'],
            'receipt.items.*.object' => ['nullable', 'integer'],
            'receipt.items.*.measurement_unit' => ['nullable', 'string', 'max:64'],
            'receipt.items.*.measurementUnit' => ['nullable', 'string', 'max:64'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.label' => ['required_with:items', 'string', 'max:255'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.vat' => ['nullable'],
            'items.*.method' => ['nullable', 'integer'],
            'items.*.object' => ['nullable', 'integer'],
            'items.*.measurement_unit' => ['nullable', 'string', 'max:64'],
            'items.*.measurementUnit' => ['nullable', 'string', 'max:64'],
            'card_id' => ['nullable', 'integer'],
        ]);

        $card = $this->selectedCard($request, $data['card_id'] ?? null);
        $currency = strtoupper((string) ($data['currency'] ?? config('payhub.currency', config('app.currency', 'RUB'))));
        $amount = (float) $data['amount'];
        $receipt = $this->receipt($request, $data, $amount, $currency);

        $order = Order::query()->create([
            'user_id' => $request->user()->id,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $data['description'] ?? null,
            'receipt' => $receipt,
            'status' => 'pending',
        ]);

        if ($card && $this->gatewayCode() === 'cloud_payments') {
            return $this->chargeSavedCloudPaymentsCard($request, $order, $card);
        }

        if ($card && $this->gatewayCode() === 'test') {
            return $this->chargeSavedTestCard($order, $card);
        }

        return response()->json([
            'ok' => true,
            'flow' => $this->gatewayCode() === 'cloud_payments' ? 'cloudpayments' : 'test',
            'order' => $this->orderPayload($order),
            'payment' => $this->paymentPayload($request, $order),
        ]);
    }

    public function destroy(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return $this->emptyResponse($request);
        }

        if ($order->status === 'pending' && $order->transaction_id === null) {
            $order->delete();
        }

        return $this->emptyResponse($request);
    }

    private function chargeSavedCloudPaymentsCard(Request $request, Order $order, Card $card): JsonResponse
    {
        try {
            $response = $this->cloudPayments->chargeByToken(
                $card,
                $order,
                (string) ($request->user()->email ?? ''),
                $request->ip() ?? '',
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'ok' => false,
                'error' => 'Unable to charge saved card.',
            ], 422);
        }

        if (($response['Success'] ?? false) !== true) {
            return response()->json([
                'ok' => false,
                'error' => $this->cloudPaymentsErrorMessage($response),
            ], 422);
        }

        $transaction = DB::transaction(function () use ($order, $response): Transaction {
            $model = is_array($response['Model'] ?? null) ? $response['Model'] : [];
            $transactionId = $model['TransactionId'] ?? $response['TransactionId'] ?? 'cloud-payments-token-'.$order->id;

            $transaction = Transaction::query()->firstOrCreate(
                ['transaction_id' => $transactionId],
                [
                    'user_id' => $order->user_id,
                    'amount' => (float) $order->amount,
                    'fee' => (float) ($model['TotalFee'] ?? $response['TotalFee'] ?? 0),
                    'status' => true,
                    'gateway' => 'CloudPayments',
                ],
            );

            $order->update([
                'transaction_id' => $transaction->id,
                'status' => 'paid',
            ]);

            return $transaction;
        });

        return response()->json([
            'ok' => true,
            'flow' => 'saved_card',
            'order' => $this->orderPayload($order->refresh()),
            'transaction' => [
                'id' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
            ],
        ]);
    }

    private function chargeSavedTestCard(Order $order, Card $card): JsonResponse
    {
        $transaction = DB::transaction(function () use ($order, $card): Transaction {
            $transaction = Transaction::query()->create([
                'user_id' => $order->user_id,
                'transaction_id' => 'test-saved-card-'.$order->id.'-'.$card->id,
                'amount' => (float) $order->amount,
                'fee' => $this->testFee((float) $order->amount),
                'status' => true,
                'gateway' => 'TestSavedCard',
            ]);

            $order->update([
                'transaction_id' => $transaction->id,
                'status' => 'paid',
            ]);

            return $transaction;
        });

        return response()->json([
            'ok' => true,
            'flow' => 'saved_card',
            'order' => $this->orderPayload($order->refresh()),
            'transaction' => [
                'id' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
            ],
        ]);
    }

    private function selectedCard(Request $request, mixed $cardId): ?Card
    {
        if (! $cardId) {
            return null;
        }

        return Card::query()
            ->where('user_id', $request->user()->id)
            ->whereKey((int) $cardId)
            ->firstOrFail();
    }

    private function selectedCardId(Request $request): ?int
    {
        return Card::query()
            ->where('user_id', $request->user()->id)
            ->where('is_default', true)
            ->value('id');
    }

    /**
     * @return array<int, array{id: int, bank: string|null, brand: string, last4: string, is_default: bool}>
     */
    private function cardsPayload(Request $request): array
    {
        return Card::query()
            ->where('user_id', $request->user()->id)
            ->get(['id', 'bank', 'brand', 'last4', 'is_default'])
            ->map(fn (Card $card): array => [
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
            'enabled' => $this->gatewayEnabled($gatewayCode),
            'testMode' => $gatewayCode === 'test',
            'publicId' => $gatewayCode === 'cloud_payments'
                ? config('payhub.gateways.cloud_payments.public_id')
                : null,
        ];
    }

    private function gatewayCode(): string
    {
        $gatewayCode = (string) config('payhub.gateway', 'test');

        return array_key_exists($gatewayCode, (array) config('payhub.gateways', []))
            ? $gatewayCode
            : 'test';
    }

    private function gatewayEnabled(string $gatewayCode): bool
    {
        return match ($gatewayCode) {
            'test' => (bool) config('payhub.test_mode'),
            'cloud_payments' => filled(config('payhub.gateways.cloud_payments.public_id'))
                && filled(config('payhub.gateways.cloud_payments.secret')),
            default => false,
        };
    }

    /**
     * @return array{id: int, amount: float, currency: string, description: string|null, status: string}
     */
    private function orderPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'description' => $order->description,
            'status' => $order->status,
        ];
    }

    /**
     * @return array{gateway: string, publicId: string|null, description: string, quantity: int, price: float, amount: float, currency: string, accountId: int, orderId: int, email: string, unit: string, receipt: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function paymentPayload(Request $request, Order $order): array
    {
        $receipt = $this->storedReceipt($order);

        return [
            'gateway' => $this->gatewayCode(),
            'publicId' => $this->gatewayCode() === 'cloud_payments'
                ? config('payhub.gateways.cloud_payments.public_id')
                : null,
            'description' => $order->description ?: 'Payment',
            'quantity' => 1,
            'price' => (float) $order->amount,
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'accountId' => (int) $request->user()->id,
            'orderId' => (int) $order->id,
            'email' => (string) ($request->user()->email ?? ''),
            'unit' => 'payment',
            'receipt' => $receipt,
            'items' => $receipt['items'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{items: array<int, array{label: string, price: float, quantity: float, amount: float, vat: mixed, method: int, object: int, measurementUnit: string}>, email: string, amounts: array<string, float>, currency: string, description: string}
     */
    private function receipt(Request $request, array $data, float $amount, string $currency): array
    {
        $receipt = is_array($data['receipt'] ?? null) ? $data['receipt'] : [];
        $receiptItems = is_array($receipt['items'] ?? null) ? $receipt['items'] : ($data['items'] ?? []);

        $items = collect($receiptItems)
            ->map(fn (array $item): array => $this->normalizeReceiptItem($item))
            ->values()
            ->all();

        if ($items === []) {
            $items = [[
                'label' => (string) ($data['description'] ?? 'Payment'),
                'price' => $amount,
                'quantity' => 1.0,
                'amount' => $amount,
                'vat' => null,
                'method' => 1,
                'object' => 4,
                'measurementUnit' => 'payment',
            ]];
        }

        $itemsAmount = round(array_sum(array_column($items, 'amount')), 2);

        abort_if($itemsAmount !== round($amount, 2), 422, 'Receipt items amount must equal payment amount.');

        $amounts = is_array($receipt['amounts'] ?? null) ? $receipt['amounts'] : [];
        $amounts['electronic'] = (float) ($amounts['electronic'] ?? $amount);

        return [
            'items' => $items,
            'email' => (string) ($receipt['email'] ?? $request->user()->email ?? ''),
            'amounts' => $amounts,
            'currency' => $currency,
            'description' => (string) ($data['description'] ?? 'Payment'),
        ];
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
    private function storedReceipt(Order $order): array
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
            : 'Unable to charge saved card.';
    }

    private function testFee(float $amount): float
    {
        $commission = (float) config('payhub.gateways.test.commission', 0);
        $vat = (float) config('payhub.gateways.test.vat', 1);

        return round($amount * $commission * $vat, 2);
    }

    private function emptyResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
