import { describe, expect, it } from 'vitest';
import { watchNavigation, type NavigationEnv } from './navigation';

const setup = () => {
    const location = { pathname: '/start' };
    const popListeners: Array<() => void> = [];
    const calls: string[] = [];
    const history = {
        pushState: (_data: unknown, _unused: string, url?: string | URL | null): void => {
            calls.push('push');
            location.pathname = new URL(String(url), 'https://example.test').pathname;
        },
    };
    const env: NavigationEnv = { history, location, onPopState: (listener) => popListeners.push(listener) };
    const changes: Array<[string, string]> = [];

    watchNavigation(env, (from, to) => changes.push([from, to]));

    return { env, location, popListeners, changes, calls };
};

describe('watchNavigation', () => {
    it('reports a change of pathname made by pushState and still calls the original', () => {
        const { env, changes, calls } = setup();

        env.history.pushState(null, '', '/next');

        expect(calls).toEqual(['push']);
        expect(changes).toEqual([['/start', '/next']]);
    });

    it('ignores a change of only the query string or the hash', () => {
        const { env, changes } = setup();

        env.history.pushState(null, '', '/start?tab=2');
        env.history.pushState(null, '', '/start#section');

        expect(changes).toEqual([]);
    });

    it('reports the back and forward buttons', () => {
        const { location, popListeners, changes } = setup();

        location.pathname = '/earlier';
        popListeners.forEach((listener) => listener());

        expect(changes).toEqual([['/start', '/earlier']]);
    });

    it('reports each change once', () => {
        const { env, location, popListeners, changes } = setup();

        env.history.pushState(null, '', '/a');
        popListeners.forEach((listener) => listener());
        location.pathname = '/b';
        popListeners.forEach((listener) => listener());
        popListeners.forEach((listener) => listener());

        expect(changes).toEqual([
            ['/start', '/a'],
            ['/a', '/b'],
        ]);
    });
});
