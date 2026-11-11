import { buildApi, replayQueued } from './api';
import { createEngagementTracker } from './engagement';
import { inertiaPagePath, isInertiaPage } from './inertia';
import { watchNavigation } from './navigation';
import { createQueue } from './queue';
import { createScrollTracker } from './scroll';
import { postEvents } from './transport';
import { viewportWidth } from './viewport';
import { wireClicks } from './wire-clicks';
import { wireEngagement } from './wire-engagement';
import { wireScroll } from './wire-scroll';

/**
 * Wire the page to the collect endpoint: the window width once per load (queued, not flushed, so the server's own page
 * view has been written before it arrives), transitions of a single-page app, file downloads, outbound clicks, scroll
 * depth, the time the visitor actively spends on each page (sent when the page is left or hidden) and the
 * `window.spaAnalytics` API. The first page view of a load is recorded by the server, so it is not sent
 * here. Every later transition is sent, Inertia visits included: the server ignores a client page view of a path it
 * just recorded, and a visit served from Inertia's prefetch cache reaches the server not at all. A `replaceState`
 * that changes the pathname counts too, but only on an Inertia page. A click on a link to
 * a file is a download and not an outbound click, even when the file is on another site.
 */
export const startEvents = (collectUrl: string, downloadExtensions: readonly string[] = []): void => {
    const queue = createQueue({
        send: (events) => postEvents(collectUrl, events),
        now: () => Date.now(),
        setTimer: (callback, ms) => window.setTimeout(callback, ms),
        clearTimer: (timer) => window.clearTimeout(timer as number),
    });

    const width = viewportWidth(window.innerWidth);

    if (width !== null) {
        queue.push({ kind: 'viewport', path: window.location.pathname, width });
    }

    const scroll = createScrollTracker((percent) => queue.push({ kind: 'scroll', path: window.location.pathname, percent }));

    const engagement = createEngagementTracker(Date.now(), document.visibilityState === 'visible' && document.hasFocus());

    const reportEngagement = (path: string): void => {
        const seconds = engagement.take(Date.now());

        if (seconds > 0) {
            queue.push({ kind: 'engagement', path, seconds });
        }
    };

    // Asked at load as well as when a replace changes the pathname: an app may strip the page object after it boots.
    let inertia = isInertiaPage(document);

    // Inertia's own boot replaces the state with the page object's url: not a visit, and the server has the page view.
    // Read lazily: the script may run before the root element is parsed.
    let bootPath = inertiaPagePath(document);
    let navigated = false;

    watchNavigation(
        {
            history: window.history,
            location: window.location,
            onPopState: (listener) => window.addEventListener('popstate', listener),
            countsReplace: () => {
                inertia = inertia || isInertiaPage(document);
                bootPath ??= inertiaPagePath(document);

                return inertia && (navigated || window.location.pathname !== bootPath);
            },
        },
        (from, to) => {
            navigated = true;
            reportEngagement(from);
            engagement.reset(Date.now());
            scroll.reset();

            queue.push({ kind: 'pageview', path: to, referrer: `${window.location.origin}${from}` }, true);
        },
    );

    wireClicks(queue, downloadExtensions);
    wireScroll(scroll);
    wireEngagement(engagement, queue, reportEngagement);

    const queued = window.spaAnalytics;
    const api = buildApi(queue.push, () => window.location.pathname);

    window.spaAnalytics = api;
    replayQueued(queued, api);
};
