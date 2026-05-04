import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { usePaymentCards } from '../hooks/use-payment-cards';

export type PaymentCard = {
    id: number;
    bank: string | null;
    brand: string;
    last4: string;
    is_default?: boolean;
};

type Endpoints = {
    data: string;
    setDefault: string;
    destroy: (cardId: number) => string;
};

const defaultEndpoints: Endpoints = {
    data: '/cards/data',
    setDefault: '/cards/default',
    destroy: (cardId) => `/cards/${cardId}`,
};

function formatBrand(brand: string): string {
    return brand.replace(/[_-]+/g, ' ').trim().toUpperCase();
}

export function useEditablePaymentCards(cards: PaymentCard[], endpoints: Partial<Endpoints> = {}, onUpdated?: () => void | Promise<void>) {
    const resolvedEndpoints = { ...defaultEndpoints, ...endpoints };
    const defaultSelectedCardId = useMemo(() => cards.find((card) => card.is_default)?.id ?? null, [cards]);
    const [editing, setEditing] = useState(false);
    const [deleteCardId, setDeleteCardId] = useState<number | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [selectedCardId, setSelectedCardId] = useState<number | null>(defaultSelectedCardId);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const selectedId = selectedCardId !== null && cards.some((card) => card.id === selectedCardId) ? selectedCardId : defaultSelectedCardId;
    const cardToDelete = useMemo(() => cards.find((card) => card.id === deleteCardId) ?? null, [cards, deleteCardId]);

    const handleSetDefault = (cardId: number): void => {
        if (editing || selectedId === cardId) {
            return;
        }

        setErrorMessage(null);
        setSelectedCardId(cardId);

        router.put(
            resolvedEndpoints.setDefault,
            { card_id: cardId },
            {
                preserveScroll: true,
                onSuccess: async () => {
                    await onUpdated?.();
                },
                onError: (errors) => {
                    setSelectedCardId(defaultSelectedCardId);
                    setErrorMessage(typeof errors.card_id === 'string' ? errors.card_id : 'Unable to update payment card.');
                },
            },
        );
    };

    const handleDelete = (): void => {
        if (deleteCardId === null) {
            return;
        }

        setIsDeleting(true);
        setErrorMessage(null);

        router.delete(resolvedEndpoints.destroy(deleteCardId), {
            preserveScroll: true,
            onSuccess: async () => {
                setDeleteCardId(null);
                await onUpdated?.();
            },
            onError: () => {
                setErrorMessage('Unable to delete payment card.');
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return {
        editing,
        setEditing,
        selectedCardId: selectedId,
        errorMessage,
        deleteCardId,
        setDeleteCardId,
        isDeleting,
        cardToDelete,
        handleSetDefault,
        handleDelete,
    };
}

export function PaymentCards({
    cards: initialCards,
    variant = 'list',
    endpoints,
    selectedCardId,
    editing = false,
    onSelect,
    onDelete,
}: {
    cards?: PaymentCard[];
    variant?: 'list' | 'compact';
    endpoints?: Partial<Endpoints>;
    selectedCardId?: number | null;
    editing?: boolean;
    onSelect?: (cardId: number) => void;
    onDelete?: (cardId: number) => void;
}) {
    const resolvedEndpoints = { ...defaultEndpoints, ...endpoints };
    const { cards: fetchedCards, hasLoaded, errorMessage } = usePaymentCards({
        enabled: initialCards === undefined,
        endpoint: resolvedEndpoints.data,
    });
    const cards = initialCards ?? fetchedCards;

    if (initialCards === undefined && !hasLoaded) {
        return <div className="rounded-lg border p-4 text-sm text-gray-500">Loading cards...</div>;
    }

    if (variant === 'compact') {
        const card = cards.find((item) => item.is_default) ?? cards[0];

        if (!card) {
            return null;
        }

        return (
            <span className="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                <span className="font-semibold">{formatBrand(card.brand)}</span>
                <span className="text-gray-500">**** {card.last4}</span>
            </span>
        );
    }

    if (cards.length === 0) {
        return (
            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-gray-500">
                {errorMessage ?? 'Saved cards will appear here after the first successful payment.'}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {errorMessage ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{errorMessage}</div> : null}
            {cards.map((card) => {
                const isSelected = selectedCardId === card.id;

                return (
                    <div
                        key={card.id}
                        role={!editing && onSelect ? 'button' : undefined}
                        tabIndex={!editing && onSelect ? 0 : -1}
                        onClick={() => onSelect?.(card.id)}
                        onKeyDown={(event) => {
                            if (!onSelect || editing) {
                                return;
                            }

                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                onSelect(card.id);
                            }
                        }}
                        className="flex items-center gap-4 rounded-lg border p-4 text-left transition hover:bg-gray-50"
                    >
                        <div className="w-24 shrink-0 rounded-md bg-gray-100 px-3 py-2 text-center text-xs font-semibold">
                            {formatBrand(card.brand)}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-medium">{card.bank || 'Payment card'}</div>
                            <div className="text-sm text-gray-500">**** {card.last4}</div>
                        </div>
                        {editing ? (
                            <button type="button" className="rounded-md px-3 py-2 text-sm text-red-600 hover:bg-red-50" onClick={() => onDelete?.(card.id)}>
                                Delete
                            </button>
                        ) : (
                            <span className={`size-5 rounded-full border ${isSelected ? 'border-blue-600 bg-blue-600' : 'border-gray-300'}`} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export function PaymentCardsDeleteDialog({
    open,
    isDeleting,
    cardLast4,
    onCancel,
    onConfirm,
}: {
    open: boolean;
    isDeleting: boolean;
    cardLast4: string | null;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 className="text-lg font-semibold">Delete payment card?</h2>
                <p className="mt-2 text-sm text-gray-600">Card {cardLast4 ? `**** ${cardLast4}` : ''} will be removed from saved payment methods.</p>
                <div className="mt-6 grid grid-cols-2 gap-3">
                    <button type="button" className="rounded-md border px-4 py-2 text-sm" disabled={isDeleting} onClick={onCancel}>
                        Cancel
                    </button>
                    <button type="button" className="rounded-md bg-red-600 px-4 py-2 text-sm text-white" disabled={isDeleting} onClick={onConfirm}>
                        Delete
                    </button>
                </div>
            </div>
        </div>
    );
}
