import type { PayloadEvent } from './types';

const xsrfHeader = (): Record<string, string> => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    const token = match?.[1];

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
};

/**
 * Post one batch. keepalive lets it finish while the page is being left; sendBeacon cannot carry the XSRF header.
 */
export const postEvents = async (url: string, events: PayloadEvent[]): Promise<void> => {
    await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...xsrfHeader() },
        body: JSON.stringify({ events }),
    });
};
