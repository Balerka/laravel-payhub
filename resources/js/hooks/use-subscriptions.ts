import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { type PayhubMessagesInput, resolvePayhubMessages } from '../translations';

export type PayhubSubscription = {
    id: number;
    subscription_id: string;
    status: boolean;
    next_transaction_at: string | null;
    amount: number | null;
    currency: string;
    description: string | null;
    interval: string | null;
    period: number | null;
};

type SubscriptionsResponse = {
    subscriptions?: PayhubSubscription[];
    currencyCode?: string;
};

export function usePayhubSubscriptions({
    enabled = true,
    endpoint = '/payhub/subscriptions/data',
    locale,
    messages,
}: {
    enabled?: boolean;
    endpoint?: string;
    locale?: string;
    messages?: PayhubMessagesInput;
} = {}) {
    const resolvedMessages = resolvePayhubMessages(messages, locale);
    const [subscriptions, setSubscriptions] = useState<PayhubSubscription[]>([]);
    const [currencyCode, setCurrencyCode] = useState('RUB');
    const [hasLoaded, setHasLoaded] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const loadSubscriptions = useCallback(async (): Promise<void> => {
        if (!enabled) {
            return;
        }

        setErrorMessage(null);

        try {
            const response = await axios.get<SubscriptionsResponse>(endpoint);
            setSubscriptions(response.data.subscriptions ?? []);

            if (response.data.currencyCode) {
                setCurrencyCode(response.data.currencyCode);
            }
        } catch {
            setErrorMessage(resolvedMessages.subscriptions.loadError);
        } finally {
            setHasLoaded(true);
        }
    }, [enabled, endpoint, resolvedMessages.subscriptions.loadError]);

    useEffect(() => {
        void loadSubscriptions();
    }, [loadSubscriptions]);

    return {
        subscriptions,
        currencyCode,
        hasLoaded,
        errorMessage,
        refreshSubscriptions: loadSubscriptions,
    };
}
