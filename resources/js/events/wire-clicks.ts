import { downloadTarget } from './download';
import { outboundTarget } from './outbound';
import type { EventQueue } from './queue';

/**
 * Report a click or middle click on a link to a file as a download, or on a link to another site as an outbound click.
 * A file wins, even when it is on another site.
 */
export const wireClicks = (queue: EventQueue, downloadExtensions: readonly string[]): void => {
    const onClick = (event: MouseEvent): void => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }

        const file = downloadTarget(link.href, window.location.href, downloadExtensions, link.hasAttribute('download'));

        if (file !== null) {
            queue.push({ kind: 'download', path: window.location.pathname, url: file }, true);

            return;
        }

        const target = outboundTarget(link.href, window.location.href);

        if (target !== null) {
            queue.push({ kind: 'outbound', path: window.location.pathname, url: target }, true);
        }
    };

    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', onClick, true);
};
