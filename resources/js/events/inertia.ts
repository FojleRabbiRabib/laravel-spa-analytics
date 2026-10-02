interface PageElement {
    tagName: string;
    textContent: string | null;
    getAttribute: (name: string) => string | null;
}

export interface PageDocument {
    querySelectorAll: (selector: string) => ArrayLike<PageElement>;
}

const isPageObject = (json: string | null): boolean => {
    if (json === null || json === '') {
        return false;
    }

    try {
        const page: unknown = JSON.parse(json);

        return typeof page === 'object' && page !== null && typeof (page as { component?: unknown }).component === 'string';
    } catch {
        return false;
    }
};

/**
 * Whether the page is an Inertia app, whose visits the server already records. Inertia puts its page object, a
 * JSON object with a `component`, in the `data-page` attribute of the root element or in the body of a
 * `<script data-page>` element. Any other `data-page` (such as a pagination widget's page number) does not count.
 */
export const isInertiaPage = (document: PageDocument): boolean =>
    Array.from(document.querySelectorAll('[data-page]')).some(
        (element) => isPageObject(element.getAttribute('data-page')) || (element.tagName === 'SCRIPT' && isPageObject(element.textContent)),
    );
