import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { type PaymentCard } from '../components/payment-cards';

type CardsResponse = {
    cards?: PaymentCard[];
};

export function usePaymentCards({
    enabled = true,
    endpoint = '/cards/data',
}: {
    enabled?: boolean;
    endpoint?: string;
} = {}) {
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
            setErrorMessage('Unable to load payment cards.');
        } finally {
            setHasLoaded(true);
        }
    }, [enabled, endpoint]);

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
