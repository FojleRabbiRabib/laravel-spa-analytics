/**
 * The extensions to report as downloads, from the comma separated list on the script tag.
 */
export const parseExtensions = (list: string | undefined): string[] =>
    (list ?? '')
        .split(',')
        .map((extension) => extension.trim().toLowerCase().replace(/^\./, ''))
        .filter((extension) => extension !== '');

/**
 * The part of a link worth reporting when it is a file download: scheme, host and path, on this site or another.
 * The query string and the fragment never leave the browser, since signed links carry secrets. A link counts when its
 * path ends in one of the extensions or it has the download attribute. Null for anything else, and for links that
 * are not http(s).
 */
export const downloadTarget = (href: string, pageHref: string, extensions: readonly string[], hasDownloadAttribute: boolean): string | null => {
    let target: URL;

    try {
        target = new URL(href, pageHref);
    } catch {
        return null;
    }

    if (target.protocol !== 'http:' && target.protocol !== 'https:') {
        return null;
    }

    const file = target.pathname.slice(target.pathname.lastIndexOf('/') + 1);
    const dot = file.lastIndexOf('.');
    const extension = dot > 0 ? file.slice(dot + 1).toLowerCase() : '';

    if (!hasDownloadAttribute && !extensions.includes(extension)) {
        return null;
    }

    return `${target.protocol}//${target.host}${target.pathname}`;
};
