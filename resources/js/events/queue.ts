import type { ClientEvent, PayloadEvent, SendBatch } from './types';

export const MAX_EVENTS = 20;
export const MAX_BYTES = 60_000;
export const DELAY_MS = 2_000;

interface Queued {
    event: ClientEvent;
    at: number;
    retried: boolean;
}

interface Batch {
    payload: PayloadEvent[];
    queued: Queued[];
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
 *
 * A batch the send reports as `retry` goes back into the queue once and is sent with the next flush; a second `retry`,
 * a `drop` and a failed send lose it.
 */
export const createQueue = ({ send, now, setTimer, clearTimer }: QueueOptions): EventQueue => {
    let pending: Queued[] = [];
    let timer: unknown = null;

    const schedule = (): void => {
        timer ??= setTimer(() => void flush(), DELAY_MS);
    };

    const flush = async (): Promise<void> => {
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }

        const taken = pending;
        pending = [];
        const sentAt = now();
        const batches: Batch[] = [];
        let batch: Batch = { payload: [], queued: [] };

        for (const queued of taken) {
            const payload: PayloadEvent = { ...queued.event, age: Math.max(0, sentAt - queued.at) };

            if (batch.payload.length > 0 && (batch.payload.length >= MAX_EVENTS || bytes([...batch.payload, payload]) > MAX_BYTES)) {
                batches.push(batch);
                batch = { payload: [], queued: [] };
            }

            batch.payload.push(payload);
            batch.queued.push(queued);
        }

        if (batch.payload.length > 0) {
            batches.push(batch);
        }

        const results = await Promise.all(batches.map((item) => send(item.payload).catch(() => 'drop' as const)));
        const again = batches.flatMap((item, index) => (results[index] === 'retry' ? item.queued.filter((queued) => !queued.retried) : []));

        if (again.length > 0) {
            pending = [...again.map((queued) => ({ ...queued, retried: true })), ...pending];
            schedule();
        }
    };

    const push = (event: ClientEvent, immediate = false): void => {
        pending.push({ event, at: now(), retried: false });

        if (immediate || pending.length >= MAX_EVENTS) {
            void flush();

            return;
        }

        schedule();
    };

    return { push, flush };
};
