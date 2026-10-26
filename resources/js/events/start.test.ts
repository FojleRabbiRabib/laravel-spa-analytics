import { afterEach, describe, expect, it, vi } from 'vitest';
import { startEvents } from './start';

interface Sent {
    kind: string;
    path: string;
    referrer?: string;
}

const boot = (inertia: boolean, pageUrl = '/start') => {
    const location = { pathname: '/start', origin: 'https://example.test', href: 'https://example.test/start' };
    const popListeners: Array<() => void> = [];
    const move = (url?: string | URL | null): void => {
        location.pathname = new URL(String(url), 'https://example.test').pathname;
    };
    const history = {
        pushState: (_data: unknown, _unused: string, url?: string | URL | null): void => move(url),
        replaceState: (_data: unknown, _unused: string, url?: string | URL | null): void => move(url),
    };
    const dom = { inertia };
    const fetchMock = vi.fn<typeof fetch>(() => Promise.resolve(new Response(null, { status: 204 })));
    const page = { tagName: 'DIV', textContent: null, getAttribute: () => `{"component":"Home","props":{},"url":"${pageUrl}"}` };

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
        querySelectorAll: () => (dom.inertia ? [page] : []),
        documentElement: { scrollHeight: 2000 },
    });

    startEvents('/spa-analytics/collect');

    const sent = (): Sent[] =>
        fetchMock.mock.calls.flatMap(([, init]) => (JSON.parse(String(init?.body)) as { events: Sent[] }).events);

    return { history, popListeners, location, sent, dom };
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

    it('reports a replaceState visit on an Inertia page, even when the app strips the page object after it boots', async () => {
        const { history, sent, dom } = boot(true);

        dom.inertia = false;
        history.replaceState(null, '', '/replaced');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview').map((event) => event.path)).toEqual(['/replaced']);
    });

    it('does not report the replaceState Inertia makes while it boots, to the url of its page object', async () => {
        const { history, sent } = boot(true, '/start/');

        history.replaceState(null, '', '/start/');
        history.pushState(null, '', '/next');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview')).toEqual([
            expect.objectContaining({ path: '/next', referrer: 'https://example.test/start/' }),
        ]);
    });

    it('still ignores the boot replace when the page object was not in the document yet as the script started', async () => {
        const { history, sent, dom } = boot(false, '/start/');

        dom.inertia = true;
        history.replaceState(null, '', '/start/');
        history.pushState(null, '', '/next');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview')).toEqual([
            expect.objectContaining({ path: '/next', referrer: 'https://example.test/start/' }),
        ]);
    });

    it('reports a replace to another path whenever it happens, and a replace back to the first url after a visit', async () => {
        const { history, sent } = boot(true, '/start/');

        history.replaceState(null, '', '/other');
        history.pushState(null, '', '/next');
        history.replaceState(null, '', '/start/');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview').map((event) => event.path)).toEqual(['/other', '/next', '/start/']);
    });

    it('does not report a replaceState on a page that is not an Inertia page', async () => {
        const { history, sent } = boot(false);

        history.replaceState(null, '', '/canonical');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview')).toEqual([]);
    });

    it('reports a transition on a page that is not an Inertia page', async () => {
        const { history, sent } = boot(false);

        history.pushState(null, '', '/next');
        await Promise.resolve();

        expect(sent().filter((event) => event.kind === 'pageview').map((event) => event.path)).toEqual(['/next']);
    });
});
