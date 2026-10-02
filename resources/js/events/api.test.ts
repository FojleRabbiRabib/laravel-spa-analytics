import { describe, expect, it } from 'vitest';
import { buildApi, replayQueued } from './api';
import type { ClientEvent } from './types';

const setup = () => {
    const pushed: Array<[ClientEvent, boolean | undefined]> = [];
    const api = buildApi((event, immediate) => pushed.push([event, immediate]), () => '/current');

    return { api, pushed };
};

describe('buildApi', () => {
    it('track sends a custom event for the current path', () => {
        const { api, pushed } = setup();

        api.track('clicked_cta', { plan: 'pro' });
        api.track('plain');

        expect(pushed).toEqual([
            [{ kind: 'event', path: '/current', name: 'clicked_cta', properties: { plan: 'pro' } }, undefined],
            [{ kind: 'event', path: '/current', name: 'plain' }, undefined],
        ]);
    });

    it('goal sends at once, with an optional value and properties', () => {
        const { api, pushed } = setup();

        api.goal('purchase', 49.5, { order: 'A1' });
        api.goal('signup');

        expect(pushed).toEqual([
            [{ kind: 'goal', path: '/current', name: 'purchase', value: 49.5, properties: { order: 'A1' } }, true],
            [{ kind: 'goal', path: '/current', name: 'signup' }, true],
        ]);
    });
});

describe('buildApi input checks', () => {
    it('drops a name the server would reject and properties that are not a plain object', () => {
        const { api, pushed } = setup();

        api.track('x'.repeat(129));
        api.track('bad name!');
        api.track('ok', 'oops' as unknown as Record<string, unknown>);
        api.track('ok', ['a'] as unknown as Record<string, unknown>);
        api.goal('ok', Number.NaN);

        expect(pushed).toEqual([
            [{ kind: 'event', path: '/current', name: 'ok' }, undefined],
            [{ kind: 'event', path: '/current', name: 'ok' }, undefined],
            [{ kind: 'goal', path: '/current', name: 'ok' }, true],
        ]);
    });
});

describe('push after the script has loaded', () => {
    it('runs a call pushed the way the queue snippet does', () => {
        const { api, pushed } = setup();

        expect(api.push(['goal', 'x', 5], ['track', 'y'])).toBe(2);

        expect(pushed.map(([event]) => event.kind)).toEqual(['goal', 'event']);
    });
});

describe('replayQueued', () => {
    it('runs calls made before the script loaded, in order, and skips anything malformed', () => {
        const { api, pushed } = setup();

        replayQueued([['track', 'early', { a: 1 }], ['goal', 'bought', 5], ['unknown', 'x'], 'junk', ['track', 42]], api);

        expect(pushed.map(([event]) => event.kind === 'event' || event.kind === 'goal' ? event.name : '')).toEqual(['early', 'bought']);
    });

    it('does nothing when there was no queue', () => {
        const { api, pushed } = setup();

        replayQueued(undefined, api);

        expect(pushed).toEqual([]);
    });
});
