import { afterEach, describe, expect, it, vi } from 'vitest';
import { startEvents } from './start';

interface Sent {
    kind: string;
    path: string;
    referrer?: string;
}

const boot = (inertia: boolean) => {
    const location = { pathname: '/start', origin: 'https://example.test', href: 'https://example.test/start' };
    const popListeners: Array<() => void> = [];
    const history = {
        pushState: (_data: unknown, _unused: string, url?: string | URL | null): void => {
            location.pathname = new URL(String(url), 'https://example.test').pathname;
        },
    };
    const fetchMock = vi.fn<typeof fetch>(() => Promise.resolve(new Response(null, { status: 204 })));
    const page = { tagName: 'DIV', textContent: null, getAttribute: () => '{"component":"Home","props":{},"url":"/start"}' };

    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('window', {
        location,
        history,
        innerWidth: 1280,
        innerHeight: 800,
        scrollY: 0,
        addEventListener: (type: string, listener: () => void) => {
            if (type === 'popstate') {
                popListeners.push(listener);
            }
        },
        setTimeout: () => 0,
        clearTimeout: () => undefined,
        requestAnimationFrame: () => 0,
    });
    vi.stubGlobal('document', {
        cookie: '',
        visibilityState: 'visible',
        hasFocus: () => true,
        addEventListener: () => undefined,
        querySelectorAll: () => (inertia ? [page] : []),
        documentElement: { scrollHeight: 2000 },
    });

    startEvents('/spa-analytics/collect');

    const sent = (): Sent[] =>
        fetchMock.mock.calls.flatMap(([, init]) => (JSON.parse(String(init?.body)) as { events: Sent[] }).events);

    return { history, popListeners, location, sent };
};

describe('startEvents', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('reports a transition on an Inertia page, because a visit served from the prefetch cache sends no request', async () => {
        const { history, sent } = boot(true);

        history.pushState(null, '', '/next');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview')).toEqual([
            expect.objectContaining({ kind: 'pageview', path: '/next', referrer: 'https://example.test/start' }),
        ]);
    });

    it('reports the back button on an Inertia page', async () => {
        const { popListeners, location, sent } = boot(true);

        location.pathname = '/earlier';
        popListeners.forEach((listener) => listener());
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview').map((event) => event.path)).toEqual(['/earlier']);
    });

    it('reports a transition on a page that is not an Inertia page', async () => {
        const { history, sent } = boot(false);

        history.pushState(null, '', '/next');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview').map((event) => event.path)).toEqual(['/next']);
    });
});
