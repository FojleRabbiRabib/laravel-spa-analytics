import { describe, expect, it } from 'vitest';
import { createScrollTracker, scrollDepth } from './scroll';

describe('createScrollTracker', () => {
    it('reports each milestone once as it is reached', () => {
        const reached: number[] = [];
        const tracker = createScrollTracker((percent) => reached.push(percent));

        tracker.update(10);
        tracker.update(26);
        tracker.update(30);
        tracker.update(80);
        tracker.update(100);
        tracker.update(100);

        expect(reached).toEqual([25, 50, 75, 100]);
    });

    it('reports every milestone passed in one jump, in order', () => {
        const reached: number[] = [];

        createScrollTracker((percent) => reached.push(percent)).update(60);

        expect(reached).toEqual([25, 50]);
    });

    it('starts again after a reset', () => {
        const reached: number[] = [];
        const tracker = createScrollTracker((percent) => reached.push(percent));

        tracker.update(30);
        tracker.reset();
        tracker.update(30);

        expect(reached).toEqual([25, 25]);
    });
});

describe('scrollDepth', () => {
    it('is how far down the page the bottom of the window has reached', () => {
        expect(scrollDepth(0, 500, 2_000)).toBe(25);
        expect(scrollDepth(1_500, 500, 2_000)).toBe(100);
        expect(scrollDepth(5_000, 500, 2_000)).toBe(100);
    });

    it('is zero for a page without height', () => {
        expect(scrollDepth(0, 500, 0)).toBe(0);
    });
});
