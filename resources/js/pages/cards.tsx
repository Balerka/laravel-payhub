import { Head } from '@inertiajs/react';
import { PaymentCards, PaymentCardsDeleteDialog, type PaymentCard, useEditablePaymentCards } from '../components/payment-cards';

export default function CardsPage({ cards }: { cards: PaymentCard[] }) {
    const manager = useEditablePaymentCards(cards);

    return (
        <main className="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-6 px-4 py-8">
            <Head title="Payment cards" />

            <header className="flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Payment cards</h1>
                    <p className="mt-1 text-sm text-gray-500">Manage saved payment methods.</p>
                </div>

                {cards.length > 0 ? (
                    <button type="button" className="rounded-md border px-4 py-2 text-sm" onClick={() => manager.setEditing(!manager.editing)}>
                        {manager.editing ? 'Done' : 'Edit'}
                    </button>
                ) : null}
            </header>

            {manager.errorMessage ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{manager.errorMessage}</div> : null}

            <PaymentCards
                cards={cards}
                selectedCardId={manager.selectedCardId}
                editing={manager.editing}
                onSelect={manager.handleSetDefault}
                onDelete={manager.setDeleteCardId}
            />

            <PaymentCardsDeleteDialog
                open={manager.deleteCardId !== null}
                isDeleting={manager.isDeleting}
                cardLast4={manager.cardToDelete?.last4 ?? null}
                onCancel={() => manager.setDeleteCardId(null)}
                onConfirm={manager.handleDelete}
            />
        </main>
    );
}
