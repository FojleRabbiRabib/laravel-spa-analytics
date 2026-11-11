import { describe, expect, it } from 'vitest';
import { readScriptConfig, sameOriginUrl } from './script-config';

const BASE = 'https://shop.test/pricing?x=1';

describe('sameOriginUrl', () => {
    it('keeps an absolute url on the same origin', () => {
        expect(sameOriginUrl('https://shop.test/spa-analytics/collect', BASE)).toBe('https://shop.test/spa-analytics/collect');
    });

    it('keeps a path relative to the page', () => {
        expect(sameOriginUrl('/spa-analytics/collect', BASE)).toBe('/spa-analytics/collect');
    });

    it.each([
        ['another host', 'https://evil.test/collect'],
        ['a subdomain', 'https://cdn.shop.test/collect'],
        ['another scheme', 'http://shop.test/collect'],
        ['another port', 'https://shop.test:8443/collect'],
        ['a protocol-relative url', '//evil.test/collect'],
        ['a javascript url', 'javascript:alert(1)'],
        ['a data url', 'data:text/plain,hello'],
    ])('rejects %s', (_label, value) => {
        expect(sameOriginUrl(value, BASE)).toBeUndefined();
    });

    it('rejects a missing or empty value', () => {
        expect(sameOriginUrl(undefined, BASE)).toBeUndefined();
        expect(sameOriginUrl('', BASE)).toBeUndefined();
    });
});

describe('readScriptConfig', () => {
    it('reads the three urls and the download list from the tag', () => {
        const config = readScriptConfig(
            { dataset: { handshake: '/h', identify: '/i', collect: '/c', downloads: 'pdf,zip' } },
            BASE,
        );

        expect(config).toEqual({ handshake: '/h', identify: '/i', collect: '/c', downloads: 'pdf,zip' });
    });

    it('drops a url that points to another origin and keeps the others', () => {
        const config = readScriptConfig({ dataset: { handshake: '/h', identify: 'https://evil.test/i', collect: '/c' } }, BASE);

        expect(config.handshake).toBe('/h');
        expect(config.identify).toBeUndefined();
        expect(config.collect).toBe('/c');
    });

    it('returns nothing without a tag', () => {
        expect(readScriptConfig(null, BASE)).toEqual({});
    });
});
