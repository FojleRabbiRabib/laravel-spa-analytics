import { describe, expect, it } from 'vitest';
import { outboundTarget } from './outbound';

const page = 'https://www.shop.test/pricing?plan=1';

describe('outboundTarget', () => {
    it('keeps the scheme, host and path of a link to another site and drops the query and fragment', () => {
        expect(outboundTarget('https://Example.org/docs/start?token=secret#top', page)).toBe('https://example.org/docs/start');
        expect(outboundTarget('http://example.org:8080/a', page)).toBe('http://example.org:8080/a');
    });

    it('ignores links that stay on the site, including www and relative ones', () => {
        expect(outboundTarget('/about', page)).toBeNull();
        expect(outboundTarget('https://shop.test/about', page)).toBeNull();
        expect(outboundTarget('https://WWW.SHOP.TEST/about', page)).toBeNull();
    });

    it('ignores anything that is not http or https', () => {
        expect(outboundTarget('mailto:me@example.org', page)).toBeNull();
        expect(outboundTarget('tel:+8801700000000', page)).toBeNull();
        expect(outboundTarget('javascript:alert(1)', page)).toBeNull();
    });

    it('ignores a link that cannot be parsed', () => {
        expect(outboundTarget('http://', page)).toBeNull();
    });
});
