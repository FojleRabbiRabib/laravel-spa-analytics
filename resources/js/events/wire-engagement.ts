import type { createEngagementTracker } from './engagement';
import type { EventQueue } from './queue';

/**
 * Tell the engagement tracker when the visitor acts, leaves or returns, and report the time and flush the queue when the
 * page is hidden or left.
 */
export const wireEngagement = (
    engagement: ReturnType<typeof createEngagementTracker>,
    queue: EventQueue,
    report: (path: string) => void,
): void => {
    for (const type of ['keydown', 'pointerdown', 'pointermove', 'touchstart', 'wheel', 'scroll']) {
        window.addEventListener(type, () => engagement.activity(Date.now()), { passive: true, capture: true });
    }

    window.addEventListener('blur', () => engagement.hidden(Date.now()));
    window.addEventListener('focus', () => {
        if (document.visibilityState === 'visible') {
            engagement.visible(Date.now());
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && document.visibilityState === 'visible') {
            engagement.visible(Date.now());
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            report(window.location.pathname);
            engagement.hidden(Date.now());
            void queue.flush();
        } else {
            engagement.visible(Date.now());
        }
    });
    window.addEventListener('pagehide', () => {
        report(window.location.pathname);
        engagement.hidden(Date.now());
        void queue.flush();
    });
};
