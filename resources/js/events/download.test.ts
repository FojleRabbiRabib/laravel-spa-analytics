import { describe, expect, it } from 'vitest';
import { downloadTarget, parseExtensions } from './download';

const page = 'https://www.shop.test/pricing?plan=1';
const extensions = ['pdf', 'zip'];

describe('parseExtensions', () => {
    it('splits, trims, lower-cases and drops dots and empty entries', () => {
        expect(parseExtensions('pdf, .ZIP,,docx ')).toEqual(['pdf', 'zip', 'docx']);
    });

    it('gives no extensions when the attribute is missing', () => {
        expect(parseExtensions(undefined)).toEqual([]);
    });
});

describe('downloadTarget', () => {
    it('reports a listed file on the site and drops the query and fragment', () => {
        expect(downloadTarget('/files/Guide.PDF?signature=secret#page=2', page, extensions, false)).toBe('https://www.shop.test/files/Guide.PDF');
    });

    it('reports a listed file on another site with its host', () => {
        expect(downloadTarget('https://cdn.example.org/a/report.zip?token=x', page, extensions, false)).toBe('https://cdn.example.org/a/report.zip');
    });

    it('ignores links that are not a listed file', () => {
        expect(downloadTarget('/about', page, extensions, false)).toBeNull();
        expect(downloadTarget('/files/photo.png', page, extensions, false)).toBeNull();
        expect(downloadTarget('/archive.pdf/page', page, extensions, false)).toBeNull();
        expect(downloadTarget('/.pdf', page, extensions, false)).toBeNull();
    });

    it('reports any link with the download attribute', () => {
        expect(downloadTarget('/export/data', page, extensions, true)).toBe('https://www.shop.test/export/data');
    });

    it('ignores anything that is not http or https, even with the download attribute', () => {
        expect(downloadTarget('mailto:me@example.org', page, extensions, true)).toBeNull();
        expect(downloadTarget('data:text/plain,hi', page, extensions, true)).toBeNull();
        expect(downloadTarget('javascript:alert(1)', page, extensions, true)).toBeNull();
    });

    it('ignores a link that cannot be parsed', () => {
        expect(downloadTarget('http://', page, extensions, true)).toBeNull();
    });
});
