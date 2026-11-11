import { xsrfHeader } from '../xsrf';
import type { PayloadEvent, SendResult } from './types';

/**
 * Answers produced before the app writes anything: an expired CSRF token, the throttle and maintenance mode. A gateway
 * error (502, 504) is not among them, because the app may have stored the batch before the gateway gave up.
 */
const NOT_PROCESSED = [419, 429, 503];

/**
 * Post one batch. keepalive lets it finish while the page is being left; sendBeacon cannot carry the XSRF header.
 *
 * A batch is worth sending again only when it certainly did not arrive: one of the answers above, or no network. A
 * request that failed while online may have reached the server, so it is dropped rather than risk counting it twice.
 */
export const postEvents = async (url: string, events: PayloadEvent[]): Promise<SendResult> => {
    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...xsrfHeader() },
            body: JSON.stringify({ events }),
        });

        if (response.ok) {
            return 'sent';
        }

        return NOT_PROCESSED.includes(response.status) ? 'retry' : 'drop';
    } catch {
        return navigator.onLine === false ? 'retry' : 'drop';
    }
};
