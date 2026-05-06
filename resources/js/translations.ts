import enPayhub from './locales/en/payhub.json';
import ruPayhub from './locales/ru/payhub.json';

export type PayhubMessages = {
    checkout: {
        title: string;
        loading: string;
        empty: string;
        loadError: string;
        testFlowUnavailable: string;
        testEndpointUnavailable: string;
        unableCreateOrder: string;
        paymentCompleted: string;
        gatewayDisabled: string;
        gatewayUnsupported: string;
        savedCardsTitle: string;
        newCard: string;
        total: string;
        processing: string;
        pay: string;
        testModeEnabled: string;
        testModeDisabled: string;
        testDebugTitle: string;
        testDebugReceipt: string;
        testDebugOrder: string;
        testDebugTransaction: string;
        itemSingular: string;
        itemPlural: string;
    };
    cards: {
        title: string;
        loading: string;
        edit: string;
        done: string;
        empty: string;
        paymentCard: string;
        delete: string;
        deleteTitle: string;
        deleteDescription: string;
        cancel: string;
        loadError: string;
        updateError: string;
        deleteError: string;
    };
    subscriptions: {
        loading: string;
        empty: string;
        subscription: string;
        nextPayment: string;
        active: string;
        cancelled: string;
        cancel: string;
        processing: string;
        loadError: string;
        cancelError: string;
    };
    refunds: {
        loading: string;
        empty: string;
        transaction: string;
        noTransactionId: string;
        paid: string;
        refunded: string;
        refund: string;
        processing: string;
        loadError: string;
        refundError: string;
    };
};

export type PayhubMessagesInput = {
    checkout?: Partial<PayhubMessages['checkout']>;
    cards?: Partial<PayhubMessages['cards']>;
    subscriptions?: Partial<PayhubMessages['subscriptions']>;
    refunds?: Partial<PayhubMessages['refunds']>;
};

export const defaultPayhubMessages: PayhubMessages = {
    ...(enPayhub as PayhubMessages),
};

export function resolvePayhubMessages(messages?: PayhubMessagesInput, locale?: string): PayhubMessages {
    const normalizedLocale = locale?.toLowerCase().split('-')[0];
    const localeDefaults = normalizedLocale === 'ru' ? (ruPayhub as PayhubMessages) : defaultPayhubMessages;

    return {
        checkout: {
            ...localeDefaults.checkout,
            ...messages?.checkout,
        },
        cards: {
            ...localeDefaults.cards,
            ...messages?.cards,
        },
        subscriptions: {
            ...localeDefaults.subscriptions,
            ...messages?.subscriptions,
        },
        refunds: {
            ...localeDefaults.refunds,
            ...messages?.refunds,
        },
    };
}

export function formatPayhubMessage(template: string, replacements: Record<string, string>): string {
    return template.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, key: string) => replacements[key] ?? '');
}
