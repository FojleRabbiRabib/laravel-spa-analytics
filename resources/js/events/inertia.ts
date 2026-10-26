interface PageElement {
    tagName: string;
    textContent: string | null;
    getAttribute: (name: string) => string | null;
}

export interface PageDocument {
    querySelectorAll: (selector: string) => ArrayLike<PageElement>;
}

const readPageObject = (json: string | null): { url?: unknown } | null => {
    if (json === null || json === '') {
        return null;
    }

    try {
        const page: unknown = JSON.parse(json);

        return typeof page === 'object' && page !== null && typeof (page as { component?: unknown }).component === 'string'
            ? (page as { url?: unknown })
            : null;
    } catch {
        return null;
    }
};

const pageObjects = (document: PageDocument): Array<{ url?: unknown }> =>
    Array.from(document.querySelectorAll('[data-page]')).flatMap((element) => {
        const page = readPageObject(element.getAttribute('data-page')) ?? (element.tagName === 'SCRIPT' ? readPageObject(element.textContent) : null);

        return page === null ? [] : [page];
    });

/**
 * Whether the page is an Inertia app. Inertia puts its page object, a JSON object with a `component`, in the
 * `data-page` attribute of the root element or in the body of a `<script data-page>` element. Any other `data-page`
 * (such as a pagination widget's page number) does not count.
 */
export const isInertiaPage = (document: PageDocument): boolean => pageObjects(document).length > 0;

/** The pathname of the `url` in the Inertia page object, or null when there is none. */
export const inertiaPagePath = (document: PageDocument): string | null => {
    const url = pageObjects(document)[0]?.url;

    if (typeof url !== 'string') {
        return null;
    }

    try {
        return new URL(url, 'https://inertia.invalid').pathname;
    } catch {
        return null;
    }
};
