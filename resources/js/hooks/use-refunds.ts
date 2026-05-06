import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { type PayhubMessagesInput, resolvePayhubMessages } from '../translations';

export type PayhubRefundTransaction = {
    id: number;
    transaction_id: string | null;
    amount: number;
    fee: number;
    income: number;
    status: boolean;
    gateway: string | null;
    created_at: string | null;
    order: {
        id: number | null;
        status: string | null;
        amount: number;
        currency: string;
        description: string | null;
    } | null;
};

type RefundsResponse = {
    transactions?: PayhubRefundTransaction[];
    currencyCode?: string;
};

export function usePayhubRefunds({
    enabled = true,
    endpoint = '/payhub/refunds/data',
    locale,
    messages,
}: {
    enabled?: boolean;
    endpoint?: string;
    locale?: string;
    messages?: PayhubMessagesInput;
} = {}) {
    const resolvedMessages = resolvePayhubMessages(messages, locale);
    const [transactions, setTransactions] = useState<PayhubRefundTransaction[]>([]);
    const [currencyCode, setCurrencyCode] = useState('RUB');
    const [hasLoaded, setHasLoaded] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const loadRefunds = useCallback(async (): Promise<void> => {
        if (!enabled) {
            return;
        }

        setErrorMessage(null);

        try {
            const response = await axios.get<RefundsResponse>(endpoint);
            setTransactions(response.data.transactions ?? []);

            if (response.data.currencyCode) {
                setCurrencyCode(response.data.currencyCode);
            }
        } catch {
            setErrorMessage(resolvedMessages.refunds.loadError);
        } finally {
            setHasLoaded(true);
        }
    }, [enabled, endpoint, resolvedMessages.refunds.loadError]);

    useEffect(() => {
        void loadRefunds();
    }, [loadRefunds]);

    return {
        transactions,
        currencyCode,
        hasLoaded,
        errorMessage,
        refreshRefunds: loadRefunds,
    };
}
