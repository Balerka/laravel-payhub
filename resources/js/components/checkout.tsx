import axios from 'axios';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { type PaymentCard } from './payment-cards';
import { formatPayhubMessage, type PayhubMessagesInput, resolvePayhubMessages } from '../translations';

export type CheckoutEndpoints = {
    data?: string;
    storeOrder?: string;
    cancelOrder?: (orderId: number) => string;
    testPay?: string | null;
};

export type CheckoutGateway = {
    code?: string;
    enabled?: boolean;
    testMode?: boolean;
    publicId?: string | null;
};

export type CheckoutItem = {
    label: string;
    price: number;
    quantity?: number;
    amount?: number;
    vat?: unknown;
    method?: number;
    object?: number;
    measurementUnit?: string;
    measurement_unit?: string;
};

export type CheckoutReceipt = {
    items?: CheckoutItem[];
    email?: string;
    amounts?: {
        electronic?: number;
        advance_payment?: number;
        credit?: number;
        provision?: number;
    };
    currency?: string;
    description?: string;
    [key: string]: unknown;
};

export type CheckoutPayment = {
    amount: number;
    currency?: string;
    description?: string | null;
    receipt?: CheckoutReceipt | null;
    items?: CheckoutItem[];
};

export type CheckoutProps = CheckoutPayment & {
    cards?: PaymentCard[];
    selectedCardId?: number | null;
    endpoints?: CheckoutEndpoints;
    gateway?: CheckoutGateway;
    locale?: string;
    messages?: PayhubMessagesInput;
    onPaid?: (payment: CheckoutPayment) => void | Promise<void>;
};

type OrderResponse = {
    ok?: boolean;
    flow?: 'cloudpayments' | 'saved_card' | 'test';
    order?: {
        id: number;
        amount: number;
        currency: string;
        description?: string | null;
    };
    payment?: {
        gateway?: string;
        publicId?: string | null;
        description?: string;
        quantity?: number;
        price?: number;
        amount?: number;
        currency?: string;
        accountId: number;
        orderId?: number;
        email?: string;
        unit?: string;
        receipt?: CheckoutReceipt;
        items?: Array<{
            label: string;
            price: number;
            quantity: number;
            amount: number;
            vat: unknown;
            method: number;
            object: number;
            measurementUnit: string;
        }>;
    };
};

type TestPaymentResponse = {
    ok?: boolean;
    transaction?: Record<string, unknown> | null;
    order?: Record<string, unknown> | null;
    card?: Record<string, unknown> | null;
    subscription?: Record<string, unknown> | null;
};

type ReceiptPreviewItem = {
    label: string;
    price: number;
    quantity: number;
    amount: number;
    vat: unknown;
    method: number;
    object: number;
    measurementUnit: string;
};

type TestPaymentDebug = {
    receiptItems: ReceiptPreviewItem[];
    order: Record<string, unknown> | null;
    transaction: Record<string, unknown> | null;
};

type CheckoutDataResponse = {
    cards?: PaymentCard[];
    selectedCardId?: number | null;
    currencyCode?: string;
    gateway?: CheckoutGateway;
};

type ResolvedCheckoutEndpoints = {
    data: string;
    storeOrder: string;
    cancelOrder: (orderId: number) => string;
    testPay: string | null;
};

const defaultEndpoints: ResolvedCheckoutEndpoints = {
    data: '/payhub/checkout/data',
    storeOrder: '/payhub/checkout/orders',
    cancelOrder: (orderId) => `/payhub/checkout/orders/${orderId}`,
    testPay: '/payhub/payments/test/pay',
};

type CloudPaymentsInstance = {
    pay: (
        operation: 'charge',
        options: Record<string, unknown>,
        callbacks?: {
            onSuccess?: () => void;
            onFail?: (reason?: string) => void;
            onComplete?: () => void;
        },
    ) => void;
};

type CloudPaymentsConstructor = new (options: Record<string, unknown>) => CloudPaymentsInstance;

declare global {
    interface Window {
        cp?: {
            CloudPayments: CloudPaymentsConstructor;
        };
    }
}

let cloudPaymentsScriptLoader: Promise<void> | null = null;

function ensureCloudPaymentsLoaded(): Promise<void> {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('window_unavailable'));
    }

    if (window.cp?.CloudPayments) {
        return Promise.resolve();
    }

    if (cloudPaymentsScriptLoader) {
        return cloudPaymentsScriptLoader;
    }

    cloudPaymentsScriptLoader = new Promise<void>((resolve, reject) => {
        const existingScript = document.querySelector<HTMLScriptElement>('script[data-cloudpayments-widget="true"]');

        if (existingScript) {
            existingScript.addEventListener('load', () => resolve(), { once: true });
            existingScript.addEventListener('error', () => reject(new Error('cloudpayments_load_error')), { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://widget.cloudpayments.ru/bundles/cloudpayments.js';
        script.async = true;
        script.dataset.cloudpaymentsWidget = 'true';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('cloudpayments_load_error'));
        document.head.appendChild(script);
    });

    return cloudPaymentsScriptLoader;
}

function isUserCancelledReason(reason: string | undefined): boolean {
    if (typeof reason !== 'string') {
        return false;
    }

    const normalizedReason = reason.trim().toLowerCase();

    return (
        normalizedReason === 'user has cancelled' ||
        normalizedReason === 'user canceled' ||
        normalizedReason === 'user cancelled' ||
        normalizedReason.includes('пользователь отменил')
    );
}

function formatAmount(value: number, currencyCode: string, locale?: string): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        maximumFractionDigits: Number.isInteger(value) ? 0 : 2,
    }).format(value);
}

function formatCardBrand(brand: string): string {
    return brand.replace(/[_-]+/g, ' ').trim().toUpperCase();
}

function receiptPreviewItems(items: CheckoutItem[], amount: number, description: string | null): ReceiptPreviewItem[] {
    if (items.length === 0) {
        return [
            {
                label: description ?? 'Payment',
                price: amount,
                quantity: 1,
                amount,
                vat: null,
                method: 1,
                object: 4,
                measurementUnit: 'payment',
            },
        ];
    }

    return items.map((item) => {
        const quantity = item.quantity ?? 1;
        const itemAmount = item.amount ?? item.price * quantity;

        return {
            label: item.label,
            price: item.price,
            quantity,
            amount: itemAmount,
            vat: item.vat ?? null,
            method: item.method ?? 1,
            object: item.object ?? 4,
            measurementUnit: item.measurementUnit ?? item.measurement_unit ?? 'payment',
        };
    });
}

function resolveReceipt({
    receipt,
    items,
    amount,
    currency,
    description,
}: {
    receipt?: CheckoutReceipt | null;
    items: CheckoutItem[];
    amount: number;
    currency?: string;
    description: string | null;
}): CheckoutReceipt {
    const receiptItems = receipt?.items ?? items;

    return {
        ...receipt,
        items: receiptItems,
        amounts: {
            electronic: amount,
            ...receipt?.amounts,
        },
        currency: receipt?.currency ?? currency,
        description: receipt?.description ?? description ?? 'Payment',
    };
}

function resolveError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const responseError = error.response?.data?.error ?? error.response?.data?.message;

        if (typeof responseError === 'string' && responseError.length > 0) {
            return responseError;
        }
    }

    if (error instanceof Error && error.message.length > 0) {
        return error.message;
    }

    return fallback;
}

export function Checkout({
    amount,
    currency,
    description = null,
    receipt = null,
    items = [],
    cards: initialCards,
    selectedCardId: initialSelectedCardId = null,
    endpoints = {},
    gateway = {},
    locale,
    messages,
    onPaid,
}: CheckoutProps) {
    const resolvedMessages = resolvePayhubMessages(messages, locale);
    const resolvedEndpoints = useMemo<ResolvedCheckoutEndpoints>(
        () => ({
            data: endpoints.data ?? defaultEndpoints.data,
            storeOrder: endpoints.storeOrder ?? defaultEndpoints.storeOrder,
            cancelOrder: endpoints.cancelOrder ?? defaultEndpoints.cancelOrder,
            testPay: endpoints.testPay ?? defaultEndpoints.testPay,
        }),
        [endpoints.cancelOrder, endpoints.data, endpoints.storeOrder, endpoints.testPay],
    );
    const [cards, setCards] = useState<PaymentCard[]>(initialCards ?? []);
    const [resolvedCurrencyCode, setResolvedCurrencyCode] = useState(currency ?? 'RUB');
    const [resolvedGateway, setResolvedGateway] = useState<CheckoutGateway>(gateway);
    const [selectedCardId, setSelectedCardId] = useState<number | null>(initialSelectedCardId);
    const [hasLoaded, setHasLoaded] = useState(initialCards !== undefined);
    const [isLoading, setIsLoading] = useState(initialCards === undefined);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [testPaymentDebug, setTestPaymentDebug] = useState<TestPaymentDebug | null>(null);
    const gatewayCode = resolvedGateway.code ?? 'test';
    const gatewayEnabled = resolvedGateway.enabled ?? (gatewayCode === 'test');
    const testMode = resolvedGateway.testMode ?? (gatewayCode === 'test');
    const payment: CheckoutPayment = {
        amount,
        currency: currency ?? resolvedCurrencyCode,
        description,
        receipt,
        items,
    };
    const resolvedReceipt = resolveReceipt({
        receipt,
        items,
        amount,
        currency: payment.currency,
        description,
    });

    const loadCheckoutData = useCallback(async (): Promise<void> => {
        if (initialCards !== undefined) {
            return;
        }

        setIsLoading(true);
        setErrorMessage(null);

        try {
            const response = await axios.get<CheckoutDataResponse>(resolvedEndpoints.data);

            setCards(response.data.cards ?? []);
            setSelectedCardId(response.data.selectedCardId ?? null);

            if (response.data.currencyCode && !currency) {
                setResolvedCurrencyCode(response.data.currencyCode);
            }

            if (response.data.gateway) {
                setResolvedGateway(response.data.gateway);
            }
        } catch (error) {
            setErrorMessage(resolveError(error, resolvedMessages.checkout.loadError));
        } finally {
            setHasLoaded(true);
            setIsLoading(false);
        }
    }, [currency, initialCards, resolvedEndpoints.data, resolvedMessages.checkout.loadError]);

    useEffect(() => {
        void loadCheckoutData();
    }, [loadCheckoutData]);

    const submit = async (): Promise<void> => {
        if (amount <= 0 || isSubmitting) {
            return;
        }

        if (!gatewayEnabled) {
            setErrorMessage(formatPayhubMessage(resolvedMessages.checkout.gatewayDisabled, { gateway: gatewayCode }));
            return;
        }

        if (gatewayCode !== 'test' && gatewayCode !== 'cloud_payments') {
            setErrorMessage(formatPayhubMessage(resolvedMessages.checkout.gatewayUnsupported, { gateway: gatewayCode }));
            return;
        }

        if (gatewayCode === 'test' && !resolvedEndpoints.testPay) {
            setErrorMessage(resolvedMessages.checkout.testEndpointUnavailable);
            return;
        }

        setIsSubmitting(true);
        setErrorMessage(null);
        setSuccessMessage(null);
        setTestPaymentDebug(null);

        try {
            const orderResponse = await axios.post<OrderResponse>(resolvedEndpoints.storeOrder, {
                amount,
                currency: payment.currency,
                description,
                receipt: resolvedReceipt,
                items,
                card_id: selectedCardId,
            });

            const orderId = orderResponse.data.order?.id;

            if (!orderId) {
                throw new Error(resolvedMessages.checkout.unableCreateOrder);
            }

            if (orderResponse.data.flow === 'saved_card') {
                setSuccessMessage(resolvedMessages.checkout.paymentCompleted);
                if (testMode) {
                    setTestPaymentDebug({
                        receiptItems: receiptPreviewItems(resolvedReceipt.items ?? [], amount, description),
                        order: orderResponse.data.order ? { ...orderResponse.data.order } : null,
                        transaction: (orderResponse.data as { transaction?: Record<string, unknown> }).transaction ?? null,
                    });
                }
                await onPaid?.(payment);
                return;
            }

            if (gatewayCode === 'cloud_payments') {
                const widgetPayment = orderResponse.data.payment;

                if (!widgetPayment?.publicId) {
                    throw new Error(resolvedMessages.checkout.gatewayDisabled);
                }

                await ensureCloudPaymentsLoaded();

                if (!window.cp?.CloudPayments) {
                    throw new Error('cloudpayments_unavailable');
                }

                const payments = new window.cp.CloudPayments({
                    language: locale === 'ru' ? 'ru-RU' : 'en-US',
                    email: widgetPayment.email,
                    applePaySupport: false,
                    googlePaySupport: false,
                    yandexPaySupport: true,
                    tinkoffInstallmentSupport: false,
                });

                payments.pay(
                    'charge',
                    {
                        publicId: widgetPayment.publicId,
                        description: widgetPayment.description,
                        amount: widgetPayment.amount ?? amount,
                        currency: widgetPayment.currency ?? payment.currency,
                        accountId: widgetPayment.accountId,
                        invoiceId: widgetPayment.orderId ?? orderId,
                        email: widgetPayment.email,
                        skin: 'modern',
                        autoClose: 3,
                        requireEmail: false,
                        data: {
                            CloudPayments: {
                                CustomerReceipt: {
                                    Items: ((widgetPayment.receipt?.items as CheckoutItem[] | undefined) ?? widgetPayment.items ?? [
                                        {
                                            label: widgetPayment.description ?? 'Payment',
                                            price: widgetPayment.price ?? amount,
                                            quantity: 1,
                                            amount: widgetPayment.amount ?? amount,
                                            vat: null,
                                            method: 1,
                                            object: 4,
                                            measurementUnit: widgetPayment.unit ?? 'payment',
                                        },
                                    ]).map((item) => ({
                                        label: item.label,
                                        price: item.price,
                                        quantity: item.quantity,
                                        amount: item.amount,
                                        vat: item.vat,
                                        method: item.method,
                                        object: item.object,
                                        measurementUnit: item.measurementUnit,
                                    })),
                                    email: widgetPayment.email,
                                    amounts: {
                                        electronic: widgetPayment.amount ?? amount,
                                    },
                                },
                            },
                        },
                    },
                    {
                        onSuccess: () => {
                            setSuccessMessage(resolvedMessages.checkout.paymentCompleted);
                            void onPaid?.(payment);
                        },
                        onFail: (reason) => {
                            if (isUserCancelledReason(reason)) {
                                void axios.delete(resolvedEndpoints.cancelOrder(orderId));
                                return;
                            }

                            setErrorMessage(reason || resolvedMessages.checkout.loadError);
                        },
                        onComplete: () => {
                            setIsSubmitting(false);
                        },
                    },
                );

                return;
            }

            if (!resolvedEndpoints.testPay) {
                throw new Error(resolvedMessages.checkout.testEndpointUnavailable);
            }

            const last4 = '4242';

            const testPaymentResponse = await axios.post<TestPaymentResponse>(resolvedEndpoints.testPay, {
                order_id: orderId,
                amount,
                currency: payment.currency,
                description,
                receipt: resolvedReceipt,
                items,
                card_token: `test-token-${orderResponse.data.payment?.accountId ?? 'user'}-${last4}`,
                card_last4: last4,
                card_brand: 'Visa',
                card_bank: 'Test Bank',
            });

            setSuccessMessage(resolvedMessages.checkout.paymentCompleted);
            setTestPaymentDebug({
                receiptItems: (orderResponse.data.payment?.items ?? receiptPreviewItems(resolvedReceipt.items ?? [], amount, description)).map((item) => ({
                    label: item.label,
                    price: item.price,
                    quantity: item.quantity,
                    amount: item.amount,
                    vat: item.vat,
                    method: item.method,
                    object: item.object,
                    measurementUnit: item.measurementUnit,
                })),
                order: testPaymentResponse.data.order ?? null,
                transaction: testPaymentResponse.data.transaction ?? null,
            });
            await onPaid?.(payment);
        } catch (error) {
            setErrorMessage(resolveError(error, resolvedMessages.checkout.loadError));
        } finally {
            setIsSubmitting(false);
        }
    };

    if (isLoading && !hasLoaded) {
        return <div className="rounded-lg border p-4 text-sm text-gray-500">{resolvedMessages.checkout.loading}</div>;
    }

    if (amount <= 0 && !errorMessage) {
        return <div className="rounded-lg border border-dashed p-8 text-center text-sm text-gray-500">{resolvedMessages.checkout.empty}</div>;
    }

    return (
        <div className="flex w-full flex-col gap-6">
            {errorMessage ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{errorMessage}</div> : null}
            {successMessage ? <div className="rounded-md bg-green-50 p-3 text-sm text-green-700">{successMessage}</div> : null}

            {cards.length > 0 ? (
                <section className="space-y-3">
                    <div className="text-sm font-medium">{resolvedMessages.checkout.savedCardsTitle}</div>
                    <div className="overflow-x-auto pb-1">
                        <div className="flex min-w-max gap-2">
                            {cards.map((card) => {
                                const isSelected = selectedCardId === card.id;

                                return (
                                    <button
                                        key={card.id}
                                        type="button"
                                        onClick={() => setSelectedCardId(card.id)}
                                        className={`min-w-32 shrink-0 rounded-md border px-3 py-2 text-left text-sm transition ${
                                            isSelected ? 'border-blue-600 bg-blue-50' : 'border-gray-200 bg-white hover:bg-gray-50'
                                        }`}
                                    >
                                        <span className="block text-xs font-semibold">{formatCardBrand(card.brand)}</span>
                                        <span className="block text-xs text-gray-500">**** {card.last4}</span>
                                    </button>
                                );
                            })}

                            <button
                                type="button"
                                onClick={() => setSelectedCardId(null)}
                                className={`min-w-40 shrink-0 rounded-md border px-3 py-2 text-left text-sm transition ${
                                    selectedCardId === null ? 'border-blue-600 bg-blue-50' : 'border-gray-200 bg-white hover:bg-gray-50'
                                }`}
                            >
                                {resolvedMessages.checkout.newCard}
                            </button>
                        </div>
                    </div>
                </section>
            ) : null}

            <section className="rounded-lg border p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <div className="text-xs text-gray-500">{resolvedMessages.checkout.total}</div>
                        <div className="text-lg font-semibold">{formatAmount(amount, payment.currency ?? resolvedCurrencyCode, locale)}</div>
                        {testMode ? <p className="mt-1 text-xs text-gray-500">{resolvedMessages.checkout.testModeEnabled}</p> : null}
                    </div>

                    <button
                        type="button"
                        onClick={() => void submit()}
                        disabled={isSubmitting || amount <= 0}
                        className="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isSubmitting ? resolvedMessages.checkout.processing : resolvedMessages.checkout.pay}
                    </button>
                </div>
            </section>

            {testMode && testPaymentDebug ? (
                <section className="space-y-3 rounded-lg border p-4">
                    <div className="text-sm font-medium">{resolvedMessages.checkout.testDebugTitle}</div>

                    <div>
                        <div className="text-xs font-medium text-gray-500">{resolvedMessages.checkout.testDebugReceipt}</div>
                        <div className="mt-2 overflow-hidden rounded-md border">
                            {testPaymentDebug.receiptItems.map((item, index) => (
                                <div key={`${item.label}-${index}`} className="grid grid-cols-[1fr_auto] gap-3 border-b p-3 text-sm last:border-b-0">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">{item.label}</div>
                                        <div className="text-xs text-gray-500">
                                            {item.quantity} x {formatAmount(item.price, payment.currency ?? resolvedCurrencyCode, locale)}
                                        </div>
                                    </div>
                                    <div className="text-sm font-medium">{formatAmount(item.amount, payment.currency ?? resolvedCurrencyCode, locale)}</div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-3 md:grid-cols-2">
                        <div>
                            <div className="text-xs font-medium text-gray-500">{resolvedMessages.checkout.testDebugOrder}</div>
                            <pre className="mt-2 max-h-72 overflow-auto rounded-md bg-gray-950 p-3 text-xs text-gray-100">
                                {JSON.stringify(testPaymentDebug.order, null, 2)}
                            </pre>
                        </div>

                        <div>
                            <div className="text-xs font-medium text-gray-500">{resolvedMessages.checkout.testDebugTransaction}</div>
                            <pre className="mt-2 max-h-72 overflow-auto rounded-md bg-gray-950 p-3 text-xs text-gray-100">
                                {JSON.stringify(testPaymentDebug.transaction, null, 2)}
                            </pre>
                        </div>
                    </div>
                </section>
            ) : null}
        </div>
    );
}
