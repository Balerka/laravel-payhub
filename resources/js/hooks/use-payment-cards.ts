import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { type PaymentCard } from '../components/payment-cards';
import { type PayhubMessagesInput, resolvePayhubMessages } from '../translations';

type CardsResponse = {
    cards?: PaymentCard[];
};

export function usePaymentCards({
    enabled = true,
    endpoint = '/payhub/cards/data',
    locale,
    messages,
}: {
    enabled?: boolean;
    endpoint?: string;
    locale?: string;
    messages?: PayhubMessagesInput;
} = {}) {
    const resolvedMessages = resolvePayhubMessages(messages, locale);
    const [cards, setCards] = useState<PaymentCard[]>([]);
    const [hasLoaded, setHasLoaded] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const loadCards = useCallback(async (): Promise<void> => {
        if (!enabled) {
            return;
        }

        setErrorMessage(null);

        try {
            const response = await axios.get<CardsResponse>(endpoint);
            setCards(response.data.cards ?? []);
        } catch {
            setErrorMessage(resolvedMessages.cards.loadError);
        } finally {
            setHasLoaded(true);
        }
    }, [enabled, endpoint, resolvedMessages.cards.loadError]);

    useEffect(() => {
        void loadCards();
    }, [loadCards]);

    return {
        cards,
        hasLoaded,
        errorMessage,
        refreshCards: loadCards,
    };
}
