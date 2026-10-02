import { describe, expect, it } from 'vitest';
import { isInertiaPage, type PageDocument } from './inertia';

const documentWith = (...elements: Array<{ tagName?: string; attribute?: string | null; text?: string | null }>): PageDocument => ({
    querySelectorAll: () =>
        elements.map((element) => ({
            tagName: element.tagName ?? 'DIV',
            textContent: element.text ?? null,
            getAttribute: () => element.attribute ?? null,
        })),
});

describe('isInertiaPage', () => {
    it('recognises the root element carrying the page object', () => {
        expect(isInertiaPage(documentWith({ attribute: '{"component":"Home","props":{},"url":"/"}' }))).toBe(true);
    });

    it('recognises the script element carrying the page object', () => {
        expect(isInertiaPage(documentWith({ tagName: 'SCRIPT', attribute: 'app', text: '{"component":"Home","props":{}}' }))).toBe(true);
    });

    it('is not fooled by other data-page attributes such as a pagination page number', () => {
        expect(isInertiaPage(documentWith({ attribute: '2' }))).toBe(false);
        expect(isInertiaPage(documentWith({ attribute: '{"page":2}' }))).toBe(false);
        expect(isInertiaPage(documentWith({ attribute: 'not json' }))).toBe(false);
    });

    it('does not read the text of an element that is not a script', () => {
        expect(isInertiaPage(documentWith({ attribute: '2', text: '{"component":"Home"}' }))).toBe(false);
    });

    it('is false when nothing carries a data-page attribute', () => {
        expect(isInertiaPage(documentWith())).toBe(false);
    });
});
