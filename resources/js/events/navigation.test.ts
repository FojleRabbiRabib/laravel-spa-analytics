import { describe, expect, it } from 'vitest';
import { watchNavigation, type NavigationEnv } from './navigation';

const setup = (countsReplace = true) => {
    const location = { pathname: '/start' };
    const popListeners: Array<() => void> = [];
    const calls: string[] = [];
    const move = (url?: string | URL | null): void => {
        location.pathname = new URL(String(url), 'https://example.test').pathname;
    };
    const history = {
        pushState: (_data: unknown, _unused: string, url?: string | URL | null): void => {
            calls.push('push');
            move(url);
        },
        replaceState: (_data: unknown, _unused: string, url?: string | URL | null): void => {
            calls.push('replace');
            move(url);
        },
    };
    const state = { countsReplace, asked: 0 };
    const env: NavigationEnv = {
        history,
        location,
        onPopState: (listener) => popListeners.push(listener),
        countsReplace: () => {
            state.asked++;

            return state.countsReplace;
        },
    };
    const changes: Array<[string, string]> = [];

    watchNavigation(env, (from, to) => changes.push([from, to]));

    return { env, location, popListeners, changes, calls, state };
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
        env.history.replaceState(null, '', '/start?tab=3');
        env.history.replaceState(null, '', '/start#other');

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

    it('reports a replaceState that changes the pathname when replaces count, and still calls the original', () => {
        const { env, changes, calls } = setup();

        env.history.replaceState(null, '', '/replaced');

        expect(calls).toEqual(['replace']);
        expect(changes).toEqual([['/start', '/replaced']]);
    });

    it('ignores a replaceState that keeps the same path, without asking whether replaces count', () => {
        const { env, changes, state } = setup();

        env.history.replaceState({ scroll: 1 }, '', '/start');
        env.history.replaceState(null, '', '/start?tab=3');
        env.history.replaceState(null, '', '/start#other');

        expect(changes).toEqual([]);
        expect(state.asked).toBe(0);
    });

    it('reports a push and a replace to the same path once', () => {
        const { env, changes } = setup();

        env.history.pushState(null, '', '/next');
        env.history.replaceState(null, '', '/next');

        expect(changes).toEqual([['/start', '/next']]);
    });

    it('does not report a replaceState when replaces do not count, but starts the next report from its path', () => {
        const { env, state, changes, calls } = setup(false);

        env.history.replaceState(null, '', '/silent');
        state.countsReplace = true;
        env.history.pushState(null, '', '/pushed');
        env.history.pushState(null, '', '/silent');

        expect(calls).toEqual(['replace', 'push', 'push']);
        expect(changes).toEqual([
            ['/silent', '/pushed'],
            ['/pushed', '/silent'],
        ]);
    });
});
