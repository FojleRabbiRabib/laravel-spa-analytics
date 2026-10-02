import { describe, expect, it } from 'vitest';
import fixture from '../../../tests/Fixtures/collect-batch.json';
import { createQueue } from './queue';
import type { ClientEvent, PayloadEvent } from './types';

describe('collect batch fixture (shared with the PHP endpoint)', () => {
    it('is exactly what the queue sends for one event of each kind', async () => {
        const sent: PayloadEvent[][] = [];
        let clock = 100_000;
        const queue = createQueue({
            send: (events) => {
                sent.push(events);

                return Promise.resolve();
            },
            now: () => clock,
            setTimer: () => null,
            clearTimer: () => undefined,
        });

        for (const { age, ...event } of fixture.events) {
            clock = 100_000 - age;
            queue.push(event as ClientEvent);
        }

        clock = 100_000;
        await queue.flush();

        expect(sent).toEqual([fixture.events]);
    });
});
