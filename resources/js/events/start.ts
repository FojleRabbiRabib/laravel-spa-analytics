import { buildApi, replayQueued } from './api';
import { isInertiaPage } from './inertia';
import { watchNavigation } from './navigation';
import { outboundTarget } from './outbound';
import { createQueue } from './queue';
import { createScrollTracker, scrollDepth } from './scroll';
import { postEvents } from './transport';

/**
 * Wire the page to the collect endpoint: transitions of a single-page app, outbound clicks, scroll depth and the
 * `window.spaAnalytics` API. The first page view of a load is recorded by the server, so it is not sent here, and
 * Inertia visits are left to the server for the same reason.
 */
export const startEvents = (collectUrl: string): void => {
    const queue = createQueue({
        send: (events) => postEvents(collectUrl, events),
        now: () => Date.now(),
        setTimer: (callback, ms) => window.setTimeout(callback, ms),
        clearTimer: (timer) => window.clearTimeout(timer as number),
    });

    const scroll = createScrollTracker((percent) => queue.push({ kind: 'scroll', path: window.location.pathname, percent }));

    watchNavigation(
        {
            history: window.history,
            location: window.location,
            onPopState: (listener) => window.addEventListener('popstate', listener),
        },
        (from, to) => {
            scroll.reset();

            if (!isInertiaPage(document)) {
                queue.push({ kind: 'pageview', path: to, referrer: `${window.location.origin}${from}` }, true);
            }
        },
    );

    const onClick = (event: MouseEvent): void => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        const target = link instanceof HTMLAnchorElement ? outboundTarget(link.href, window.location.href) : null;

        if (target !== null) {
            queue.push({ kind: 'outbound', path: window.location.pathname, url: target }, true);
        }
    };

    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', onClick, true);

    let frame = 0;

    window.addEventListener(
        'scroll',
        () => {
            if (frame !== 0) {
                return;
            }

            frame = window.requestAnimationFrame(() => {
                frame = 0;
                scroll.update(scrollDepth(window.scrollY, window.innerHeight, document.documentElement.scrollHeight));
            });
        },
        { passive: true },
    );

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            void queue.flush();
        }
    });
    window.addEventListener('pagehide', () => void queue.flush());

    const queued = window.spaAnalytics;
    const api = buildApi(queue.push, () => window.location.pathname);

    window.spaAnalytics = api;
    replayQueued(queued, api);
};
