import axios from 'axios';
import { useState } from 'react';
import { type PayhubMessagesInput, resolvePayhubMessages } from '../translations';
import { type PayhubSubscription, usePayhubSubscriptions } from '../hooks/use-subscriptions';

type Endpoints = {
    data: string;
    cancel: string;
};

const defaultEndpoints: Endpoints = {
    data: '/payhub/subscriptions/data',
    cancel: '/payhub/subscriptions/cancel',
};

function formatAmount(value: number | null, currencyCode: string, locale?: string): string {
    if (value === null) {
        return '';
    }

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        maximumFractionDigits: Number.isInteger(value) ? 0 : 2,
    }).format(value);
}

function formatDate(value: string | null, locale?: string): string {
    if (!value) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
    }).format(new Date(value));
}

export function PayhubSubscriptions({
    subscriptions: initialSubscriptions,
    endpoints = {},
    locale,
    messages,
    onCancelled,
}: {
    subscriptions?: PayhubSubscription[];
    endpoints?: Partial<Endpoints>;
    locale?: string;
    messages?: PayhubMessagesInput;
    onCancelled?: (subscription: PayhubSubscription) => void | Promise<void>;
}) {
    const resolvedMessages = resolvePayhubMessages(messages, locale);
    const resolvedEndpoints = { ...defaultEndpoints, ...endpoints };
    const { subscriptions: fetchedSubscriptions, currencyCode, hasLoaded, errorMessage, refreshSubscriptions } = usePayhubSubscriptions({
        enabled: initialSubscriptions === undefined,
        endpoint: resolvedEndpoints.data,
        locale,
        messages,
    });
    const [processingId, setProcessingId] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const subscriptions = initialSubscriptions ?? fetchedSubscriptions;

    const cancel = async (subscription: PayhubSubscription): Promise<void> => {
        if (processingId) {
            return;
        }

        setProcessingId(subscription.subscription_id);
        setActionError(null);

        try {
            await axios.post(resolvedEndpoints.cancel, {
                subscription_id: subscription.subscription_id,
            });
            await refreshSubscriptions();
            await onCancelled?.(subscription);
        } catch (error) {
            const message = axios.isAxiosError(error) && typeof error.response?.data?.error === 'string'
                ? error.response.data.error
                : resolvedMessages.subscriptions.cancelError;
            setActionError(message);
        } finally {
            setProcessingId(null);
        }
    };

    if (initialSubscriptions === undefined && !hasLoaded) {
        return <div className="rounded-lg border p-4 text-sm text-gray-500">{resolvedMessages.subscriptions.loading}</div>;
    }

    if (subscriptions.length === 0) {
        return <div className="rounded-lg border border-dashed p-8 text-center text-sm text-gray-500">{errorMessage ?? resolvedMessages.subscriptions.empty}</div>;
    }

    return (
        <div className="space-y-3">
            {errorMessage || actionError ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{actionError ?? errorMessage}</div> : null}
            {subscriptions.map((subscription) => {
                const currency = subscription.currency || currencyCode;

                return (
                    <div key={subscription.id} className="rounded-lg border p-4">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="font-medium">{subscription.description ?? resolvedMessages.subscriptions.subscription}</div>
                                <div className="mt-1 text-sm text-gray-500">
                                    {formatAmount(subscription.amount, currency, locale)}
                                    {subscription.period && subscription.interval ? ` / ${subscription.period} ${subscription.interval}` : ''}
                                </div>
                                {subscription.next_transaction_at ? (
                                    <div className="mt-1 text-xs text-gray-500">
                                        {resolvedMessages.subscriptions.nextPayment}: {formatDate(subscription.next_transaction_at, locale)}
                                    </div>
                                ) : null}
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-2">
                                <span className={`rounded-md px-2 py-1 text-xs ${subscription.status ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                                    {subscription.status ? resolvedMessages.subscriptions.active : resolvedMessages.subscriptions.cancelled}
                                </span>
                                {subscription.status ? (
                                    <button
                                        type="button"
                                        onClick={() => void cancel(subscription)}
                                        disabled={processingId !== null}
                                        className="rounded-md px-3 py-2 text-sm text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {processingId === subscription.subscription_id ? resolvedMessages.subscriptions.processing : resolvedMessages.subscriptions.cancel}
                                    </button>
                                ) : null}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
