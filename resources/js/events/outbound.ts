const withoutWww = (host: string): string => host.toLowerCase().replace(/^www\./, '');

/**
 * The part of a link worth reporting when it leaves the site: scheme, host and path. The query string and the
 * fragment never leave the browser. Null for links that stay on the site or are not http(s), such as mailto: links.
 */
export const outboundTarget = (href: string, pageHref: string): string | null => {
    let target: URL;
    let page: URL;

    try {
        target = new URL(href, pageHref);
        page = new URL(pageHref);
    } catch {
        return null;
    }

    if (target.protocol !== 'http:' && target.protocol !== 'https:') {
        return null;
    }

    if (withoutWww(target.hostname) === withoutWww(page.hostname)) {
        return null;
    }

    return `${target.protocol}//${target.host}${target.pathname}`;
};
