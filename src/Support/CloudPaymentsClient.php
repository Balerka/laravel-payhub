<?php

namespace Balerka\LaravelPayhub\Support;

use Balerka\LaravelPayhub\Models\Card;
use Balerka\LaravelPayhub\Models\Order;
use Balerka\LaravelPayhub\Models\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CloudPaymentsClient
{
    protected string $baseUrl;

    protected string $publicKey;

    protected string $privateKey;

    protected string $locale;

    protected bool $enableSsl;

    public function __construct(bool $enableSsl = true)
    {
        $this->baseUrl = (string) config('payhub.gateways.cloud_payments.api_url');
        $this->publicKey = (string) config('payhub.gateways.cloud_payments.public_id');
        $this->privateKey = (string) config('payhub.gateways.cloud_payments.secret');
        $this->locale = app()->getLocale() === 'ru' ? 'ru-RU' : 'en-US';
        $this->enableSsl = $enableSsl;
    }

    /**
     * @return array<string, mixed>
     */
    public function chargeByToken(Card $card, Order $order, string $email, string $ipAddress): array
    {
        return $this->sendRequest('/payments/tokens/charge', [
            'Amount' => (float) $order->amount,
            'Currency' => $order->currency ?: config('payhub.currency', config('app.currency', 'RUB')),
            'AccountId' => (string) $order->user_id,
            'TrInitiatorCode' => 1,
            'Token' => $card->token,
            'InvoiceId' => (string) $order->id,
            'Description' => $order->description ?: 'Payment',
            'IpAddress' => $ipAddress,
            'Email' => $email,
            'JsonData' => json_encode([
                'cloudpayments' => [
                    'CustomerReceipt' => $this->receipt(
                        $order->description ?: 'Payment',
                        (float) $order->amount,
                        $email,
                        $this->storedReceipt($order),
                    ),
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSubscriptions(int|string $accountId): array
    {
        if ($this->privateKey === '') {
            return [];
        }

        $response = $this->sendRequest('/subscriptions/find', [
            'accountId' => $accountId,
        ]);

        return ($response['Success'] ?? false) === true && is_array($response['Model'] ?? null)
            ? $response['Model']
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubscription(string $subscriptionId): ?array
    {
        $response = $this->sendRequest('/subscriptions/get', [
            'Id' => $subscriptionId,
        ]);

        return ($response['Success'] ?? false) === true && is_array($response['Model'] ?? null)
            ? $response['Model']
            : null;
    }

    /**
     * @param  array<string, mixed>  $additionalParams
     * @return array<string, mixed>|null
     */
    public function createSubscription(
        string $token,
        Model $user,
        string $startIn,
        float $amount,
        string $description,
        ?string $interval,
        ?int $period,
        array $additionalParams = [],
    ): ?array {
        $email = (string) ($user->email ?? '');
        $params = array_merge([
            'Token' => $token,
            'AccountId' => $user->getKey(),
            'Description' => $description,
            'Amount' => $amount,
            'Currency' => config('payhub.currency', config('app.currency', 'RUB')),
            'RequireConfirmation' => true,
            'StartDate' => now()->add($startIn)->toAtomString(),
            'Interval' => $interval,
            'Period' => $period,
            'Email' => $email,
            'CustomerReceipt' => $this->receipt($description, $amount, $email),
        ], $additionalParams);

        $response = $this->sendRequest('/subscriptions/create', $params);

        return ($response['Success'] ?? false) === true && is_array($response['Model'] ?? null)
            ? $response['Model']
            : null;
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        $response = $this->sendRequest('/subscriptions/cancel', [
            'Id' => $subscriptionId,
        ]);

        return ($response['Success'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $updateParams
     */
    public function updateSubscription(string $subscriptionId, array $updateParams): bool
    {
        $response = $this->sendRequest('/subscriptions/update', array_merge([
            'Id' => $subscriptionId,
        ], $updateParams));

        return ($response['Success'] ?? false) === true;
    }

    public function confirmPayment(Transaction $transaction): bool
    {
        $response = $this->sendRequest('/payments/confirm', [
            'TransactionId' => $transaction->transaction_id,
            'Amount' => (float) $transaction->amount,
        ]);

        return ($response['Success'] ?? false) === true;
    }

    public function voidPayment(string $transactionId): bool
    {
        $response = $this->sendRequest('/payments/void', [
            'TransactionId' => $transactionId,
        ]);

        return ($response['Success'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getPayment(string $transactionId): array|false
    {
        $response = $this->sendRequest('/payments/get', [
            'TransactionId' => $transactionId,
        ]);

        return ($response['Success'] ?? false) === true && is_array($response['Model'] ?? null)
            ? $response['Model']
            : false;
    }

    public function refund(string $transactionId, float $amount): bool
    {
        $response = $this->sendRequest('/payments/refund', [
            'TransactionId' => $transactionId,
            'Amount' => $amount,
        ]);

        return ($response['Success'] ?? false) === true;
    }

    protected function getClient(): Client
    {
        return new Client([
            'base_uri' => $this->baseUrl,
            'auth' => [$this->publicKey, $this->privateKey],
            'timeout' => 20,
            'verify' => $this->enableSsl,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-ID' => Str::random(9),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function sendRequest(string $endpoint, array $params = []): array
    {
        try {
            $response = $this->getClient()->post($endpoint, [
                'json' => array_merge($params, ['CultureName' => $this->locale]),
            ]);

            return json_decode((string) $response->getBody(), true) ?: [
                'Success' => false,
                'Message' => 'CloudPayments returned an empty response.',
            ];
        } catch (GuzzleException $exception) {
            return [
                'Success' => false,
                'Message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{Items: array<int, array{Label: string, Price: float, Quantity: float, Amount: float, Vat: mixed, Method: int, Object: int, MeasurementUnit: string}>, Email: string, Amounts: array<string, float>}
     */
    private function receipt(string $description, float $amount, string $email, array $receipt = []): array
    {
        $items = is_array($receipt['items'] ?? null) ? $receipt['items'] : [];
        $receiptItems = $items === []
            ? [[
                'label' => $description,
                'price' => $amount,
                'quantity' => 1,
                'amount' => $amount,
                'vat' => null,
                'method' => 1,
                'object' => 4,
                'measurementUnit' => 'payment',
            ]]
            : $items;

        return [
            'Items' => array_map(fn (array $item): array => [
                'Label' => (string) $item['label'],
                'Price' => (float) $item['price'],
                'Quantity' => (float) $item['quantity'],
                'Amount' => (float) $item['amount'],
                'Vat' => $item['vat'] ?? null,
                'Method' => (int) ($item['method'] ?? 1),
                'Object' => (int) ($item['object'] ?? 4),
                'MeasurementUnit' => (string) ($item['measurementUnit'] ?? 'payment'),
            ], $receiptItems),
            'Email' => (string) ($receipt['email'] ?? $email),
            'Amounts' => $this->receiptAmounts($receipt, $amount),
        ];
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, float>
     */
    private function receiptAmounts(array $receipt, float $amount): array
    {
        $amounts = is_array($receipt['amounts'] ?? null) ? $receipt['amounts'] : [];

        return [
            'Electronic' => (float) ($amounts['electronic'] ?? $amount),
            'AdvancePayment' => (float) ($amounts['advance_payment'] ?? 0),
            'Credit' => (float) ($amounts['credit'] ?? 0),
            'Provision' => (float) ($amounts['provision'] ?? 0),
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
}
