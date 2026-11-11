import { createScrollTracker, scrollDepth } from './scroll';

/**
 * Feed the scroll depth to the tracker, at most once per animation frame.
 */
export const wireScroll = (scroll: ReturnType<typeof createScrollTracker>): void => {
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
};
