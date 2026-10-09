import { describe, expect, it } from 'vitest';
import { createEngagementTracker, IDLE_MS, MAX_SECONDS } from './engagement';

describe('createEngagementTracker', () => {
    it('counts the time up to the end of the idle window after the last action', () => {
        const tracker = createEngagementTracker(0, true);

        tracker.activity(5_000);

        expect(tracker.take(60_000)).toBe(5 + IDLE_MS / 1000);
    });

    it('keeps counting while the visitor keeps acting', () => {
        const tracker = createEngagementTracker(0, true);

        for (let at = 10_000; at <= 120_000; at += 10_000) {
            tracker.activity(at);
        }

        expect(tracker.take(120_000)).toBe(120);
    });

    it('does not count the time between the idle window and the next action', () => {
        const tracker = createEngagementTracker(0, true);

        tracker.activity(1_000);
        tracker.activity(100_000);

        expect(tracker.take(100_000)).toBe(16);
    });

    it('stops counting while hidden and starts again when visible', () => {
        const tracker = createEngagementTracker(0, true);

        tracker.hidden(10_000);
        tracker.activity(20_000);
        tracker.visible(500_000);

        expect(tracker.take(505_000)).toBe(15);
    });

    it('counts nothing for a page that starts hidden until it becomes visible', () => {
        const tracker = createEngagementTracker(0, false);

        expect(tracker.take(50_000)).toBe(0);

        tracker.visible(60_000);

        expect(tracker.take(64_000)).toBe(4);
    });

    it('reports whole seconds and keeps the remainder for the next report', () => {
        const tracker = createEngagementTracker(0, true);

        expect(tracker.take(1_500)).toBe(1);
        expect(tracker.take(2_200)).toBe(1);
    });

    it('reports nothing under one second and keeps the time', () => {
        const tracker = createEngagementTracker(0, true);

        expect(tracker.take(600)).toBe(0);
        expect(tracker.take(1_200)).toBe(1);
    });

    it('never reports more than the cap', () => {
        const tracker = createEngagementTracker(0, true);

        for (let at = 10_000; at <= 5_000_000; at += 10_000) {
            tracker.activity(at);
        }

        expect(tracker.take(5_000_000)).toBe(MAX_SECONDS);
    });

    it('starts a new page from zero on reset', () => {
        const tracker = createEngagementTracker(0, true);

        tracker.activity(9_000);
        tracker.reset(10_000);

        expect(tracker.take(14_000)).toBe(4);
    });

    it('keeps a hidden page hidden after reset', () => {
        const tracker = createEngagementTracker(0, true);

        tracker.hidden(2_000);
        tracker.reset(5_000);

        expect(tracker.take(20_000)).toBe(0);
    });
});
