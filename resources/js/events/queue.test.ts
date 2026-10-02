import { describe, expect, it } from 'vitest';
import { createQueue, DELAY_MS, MAX_EVENTS } from './queue';
import type { ClientEvent, PayloadEvent } from './types';

const setup = () => {
    const sent: PayloadEvent[][] = [];
    const timers: Array<{ callback: () => void; ms: number; cleared: boolean }> = [];
    let clock = 1_000;

    const queue = createQueue({
        send: (events) => {
            sent.push(events);

            return Promise.resolve();
        },
        now: () => clock,
        setTimer: (callback, ms) => {
            const timer = { callback, ms, cleared: false };
            timers.push(timer);

            return timer;
        },
        clearTimer: (timer) => {
            (timer as { cleared: boolean }).cleared = true;
        },
    });

    return { queue, sent, timers, advance: (ms: number) => (clock += ms) };
};

const view = (path: string): ClientEvent => ({ kind: 'pageview', path });

describe('createQueue', () => {
    it('holds events until the timer fires and sends them as one batch with their ages', async () => {
        const { queue, sent, timers, advance } = setup();

        queue.push(view('/a'));
        advance(500);
        queue.push(view('/b'));

        expect(sent).toHaveLength(0);
        expect(timers).toHaveLength(1);
        expect(timers[0]?.ms).toBe(DELAY_MS);

        advance(1_000);
        timers[0]?.callback();
        await queue.flush();

        expect(sent).toHaveLength(1);
        expect(sent[0]?.map((event) => event.age)).toEqual([1_500, 1_000]);
    });

    it('sends immediately when asked and cancels the pending timer', async () => {
        const { queue, sent, timers } = setup();

        queue.push(view('/a'));
        queue.push(view('/b'), true);
        await Promise.resolve();

        expect(sent).toHaveLength(1);
        expect(sent[0]).toHaveLength(2);
        expect(timers[0]?.cleared).toBe(true);
    });

    it('never puts more than 20 events in a request', async () => {
        const { queue, sent } = setup();

        for (let index = 0; index < MAX_EVENTS + 5; index++) {
            queue.push(view(`/p${index}`));
        }

        await queue.flush();

        expect(sent.map((batch) => batch.length)).toEqual([MAX_EVENTS, 5]);
    });

    it('splits a batch that would be too large for a keepalive request', async () => {
        const { queue, sent } = setup();

        for (let index = 0; index < 10; index++) {
            queue.push({ kind: 'event', path: '/a', name: 'big', properties: { text: 'x'.repeat(10_000) } });
        }

        await queue.flush();

        expect(sent.length).toBeGreaterThan(1);

        for (const batch of sent) {
            expect(new TextEncoder().encode(JSON.stringify({ events: batch })).length).toBeLessThanOrEqual(60_000);
        }
    });

    it('does nothing when there is nothing to send and survives a failing send', async () => {
        const failing = createQueue({
            send: () => Promise.reject(new Error('offline')),
            now: () => 0,
            setTimer: () => null,
            clearTimer: () => undefined,
        });

        await failing.flush();
        failing.push(view('/a'), true);
        await expect(failing.flush()).resolves.toBeUndefined();
    });
});
