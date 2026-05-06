import axios from 'axios';
import { useState } from 'react';
import { type PayhubMessagesInput, resolvePayhubMessages } from '../translations';
import { type PayhubRefundTransaction, usePayhubRefunds } from '../hooks/use-refunds';

type Endpoints = {
    data: string;
    refund: string;
};

const defaultEndpoints: Endpoints = {
    data: '/payhub/refunds/data',
    refund: '/payhub/refunds/refund',
};

function formatAmount(value: number, currencyCode: string, locale?: string): string {
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

export function PayhubRefunds({
    transactions: initialTransactions,
    endpoints = {},
    locale,
    messages,
    onRefunded,
}: {
    transactions?: PayhubRefundTransaction[];
    endpoints?: Partial<Endpoints>;
    locale?: string;
    messages?: PayhubMessagesInput;
    onRefunded?: (transaction: PayhubRefundTransaction) => void | Promise<void>;
}) {
    const resolvedMessages = resolvePayhubMessages(messages, locale);
    const resolvedEndpoints = { ...defaultEndpoints, ...endpoints };
    const { transactions: fetchedTransactions, currencyCode, hasLoaded, errorMessage, refreshRefunds } = usePayhubRefunds({
        enabled: initialTransactions === undefined,
        endpoint: resolvedEndpoints.data,
        locale,
        messages,
    });
    const [processingId, setProcessingId] = useState<number | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const transactions = initialTransactions ?? fetchedTransactions;

    const refund = async (transaction: PayhubRefundTransaction): Promise<void> => {
        if (processingId) {
            return;
        }

        setProcessingId(transaction.id);
        setActionError(null);

        try {
            await axios.post(resolvedEndpoints.refund, {
                transaction_id: transaction.id,
            });
            await refreshRefunds();
            await onRefunded?.(transaction);
        } catch (error) {
            const message = axios.isAxiosError(error) && typeof error.response?.data?.error === 'string'
                ? error.response.data.error
                : resolvedMessages.refunds.refundError;
            setActionError(message);
        } finally {
            setProcessingId(null);
        }
    };

    if (initialTransactions === undefined && !hasLoaded) {
        return <div className="rounded-lg border p-4 text-sm text-gray-500">{resolvedMessages.refunds.loading}</div>;
    }

    if (transactions.length === 0) {
        return <div className="rounded-lg border border-dashed p-8 text-center text-sm text-gray-500">{errorMessage ?? resolvedMessages.refunds.empty}</div>;
    }

    return (
        <div className="space-y-3">
            {errorMessage || actionError ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{actionError ?? errorMessage}</div> : null}
            {transactions.map((transaction) => {
                return (
                    <div key={transaction.id} className="rounded-lg border p-4">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="font-medium">{transaction.order?.description ?? resolvedMessages.refunds.transaction}</div>
                                <div className="mt-1 text-sm text-gray-500">
                                    {formatAmount(transaction.amount, currencyCode, locale)}
                                    {transaction.created_at ? ` · ${formatDate(transaction.created_at, locale)}` : ''}
                                </div>
                                <div className="mt-1 text-xs text-gray-500">
                                    {transaction.transaction_id ?? resolvedMessages.refunds.noTransactionId}
                                </div>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-2">
                                <span className={`rounded-md px-2 py-1 text-xs ${transaction.status ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                                    {transaction.status ? resolvedMessages.refunds.paid : resolvedMessages.refunds.refunded}
                                </span>
                                {transaction.status ? (
                                    <button
                                        type="button"
                                        onClick={() => void refund(transaction)}
                                        disabled={processingId !== null}
                                        className="rounded-md px-3 py-2 text-sm text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {processingId === transaction.id ? resolvedMessages.refunds.processing : resolvedMessages.refunds.refund}
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
