import { afterEach, describe, expect, it, vi } from 'vitest';
import { identify } from './handshake-client';

const FLAG = 'spa_analytics_identified';
const keyBase64 = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))));

const stubBrowser = (cookie: string, flag?: string): Record<string, string> => {
    const store: Record<string, string> = flag ? { [FLAG]: flag } : {};

    vi.stubGlobal('document', { cookie });
    vi.stubGlobal('sessionStorage', {
        getItem: (key: string): string | null => store[key] ?? null,
        setItem: (key: string, value: string): void => {
            store[key] = value;
        },
    });

    return store;
};

const reply = (ok: boolean, body: unknown = {}): Response => ({ ok, json: async () => body }) as unknown as Response;

const handshakeBody = { nonce: 'abc.def', key: keyBase64, expires_at: 9999999999 };

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('identify', () => {
    it('does nothing when this session was already identified', async () => {
        stubBrowser('', '1');
        const fetchMock = vi.fn<typeof fetch>();
        vi.stubGlobal('fetch', fetchMock);

        await identify('/handshake', '/identify');

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('stops after a failed handshake and leaves the flag unset', async () => {
        const store = stubBrowser('');
        const fetchMock = vi.fn<typeof fetch>().mockResolvedValueOnce(reply(false));
        vi.stubGlobal('fetch', fetchMock);

        await identify('/handshake', '/identify');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(store[FLAG]).toBeUndefined();
    });

    it('sends the encrypted body and sets the flag when identify succeeds', async () => {
        const store = stubBrowser('XSRF-TOKEN=tok%3D%3D; other=1');
        const fetchMock = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(reply(true, handshakeBody))
            .mockResolvedValueOnce(reply(true));
        vi.stubGlobal('fetch', fetchMock);

        await identify('/handshake', '/identify');

        expect(fetchMock).toHaveBeenCalledTimes(2);

        const [handshakeUrl, handshakeInit] = fetchMock.mock.calls[0] ?? [];
        expect(handshakeUrl).toBe('/handshake');
        expect(handshakeInit?.method).toBe('POST');
        expect(handshakeInit?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'tok==' });

        const [identifyUrl, identifyInit] = fetchMock.mock.calls[1] ?? [];
        expect(identifyUrl).toBe('/identify');
        expect(identifyInit?.headers).toMatchObject({
            'Content-Type': 'application/octet-stream',
            'X-XSRF-TOKEN': 'tok==',
        });
        expect(identifyInit?.body).toBeInstanceOf(Uint8Array);
        expect(store[FLAG]).toBe('1');
    });

    it('leaves the flag unset when identify is rejected', async () => {
        const store = stubBrowser('');
        const fetchMock = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(reply(true, handshakeBody))
            .mockResolvedValueOnce(reply(false));
        vi.stubGlobal('fetch', fetchMock);

        await identify('/handshake', '/identify');

        expect(store[FLAG]).toBeUndefined();
    });

    it('omits the XSRF header when the cookie is absent', async () => {
        stubBrowser('');
        const fetchMock = vi.fn<typeof fetch>().mockResolvedValueOnce(reply(false));
        vi.stubGlobal('fetch', fetchMock);

        await identify('/handshake', '/identify');

        const [, init] = fetchMock.mock.calls[0] ?? [];
        expect(init?.headers).not.toHaveProperty('X-XSRF-TOKEN');
    });
});
