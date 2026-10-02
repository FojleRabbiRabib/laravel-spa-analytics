import type { ClientEvent, PayloadEvent, SendBatch } from './types';

export const MAX_EVENTS = 20;
export const MAX_BYTES = 60_000;
export const DELAY_MS = 2_000;

interface Queued {
    event: ClientEvent;
    at: number;
}

interface QueueOptions {
    send: SendBatch;
    now: () => number;
    setTimer: (callback: () => void, ms: number) => unknown;
    clearTimer: (timer: unknown) => void;
}

export interface EventQueue {
    push: (event: ClientEvent, immediate?: boolean) => void;
    flush: () => Promise<void>;
}

const bytes = (events: PayloadEvent[]): number => new TextEncoder().encode(JSON.stringify({ events })).length;

/**
 * Batch the events the way the server accepts them: at most 20 per request and well under the 64 KB a keepalive
 * request may carry. Each event is sent with its age at the moment of sending, so the server can place it in time.
 */
export const createQueue = ({ send, now, setTimer, clearTimer }: QueueOptions): EventQueue => {
    let pending: Queued[] = [];
    let timer: unknown = null;

    const flush = async (): Promise<void> => {
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }

        const taken = pending;
        pending = [];
        const sentAt = now();
        const batches: PayloadEvent[][] = [];
        let batch: PayloadEvent[] = [];

        for (const { event, at } of taken) {
            const payload: PayloadEvent = { ...event, age: Math.max(0, sentAt - at) };

            if (batch.length > 0 && (batch.length >= MAX_EVENTS || bytes([...batch, payload]) > MAX_BYTES)) {
                batches.push(batch);
                batch = [];
            }

            batch.push(payload);
        }

        if (batch.length > 0) {
            batches.push(batch);
        }

        await Promise.all(batches.map((events) => send(events).catch(() => undefined)));
    };

    const push = (event: ClientEvent, immediate = false): void => {
        pending.push({ event, at: now() });

        if (immediate || pending.length >= MAX_EVENTS) {
            void flush();

            return;
        }

        timer ??= setTimer(() => void flush(), DELAY_MS);
    };

    return { push, flush };
};
