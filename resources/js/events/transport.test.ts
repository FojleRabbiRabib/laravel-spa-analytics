import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { postEvents } from './transport';
import type { PayloadEvent } from './types';

const events: PayloadEvent[] = [{ kind: 'pageview', path: '/a', age: 0 }];

const respond = (status: number): void => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: status >= 200 && status < 300, status }));
};

describe('postEvents', () => {
    beforeEach(() => {
        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc%3D' });
        vi.stubGlobal('navigator', { onLine: true });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the batch with the XSRF header and keepalive', async () => {
        respond(204);

        await postEvents('/collect', events);

        const [url, init] = vi.mocked(fetch).mock.calls[0] ?? [];
        expect(url).toBe('/collect');
        expect(init).toMatchObject({ method: 'POST', keepalive: true, credentials: 'same-origin' });
        expect((init?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
    });

    it('reports a stored batch as sent', async () => {
        respond(204);

        expect(await postEvents('/collect', events)).toBe('sent');
    });

    it.each([419, 429, 503])('asks for a retry on a %i answer, which is given before anything is written', async (status) => {
        respond(status);

        expect(await postEvents('/collect', events)).toBe('retry');
    });

    it.each([400, 403, 422, 500, 502, 504])('drops the batch on a %i answer, because the app may have stored it', async (status) => {
        respond(status);

        expect(await postEvents('/collect', events)).toBe('drop');
    });

    it('asks for a retry when the browser is offline', async () => {
        vi.stubGlobal('navigator', { onLine: false });
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        expect(await postEvents('/collect', events)).toBe('retry');
    });

    it('drops the batch when the request failed while online, because the server may have it', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        expect(await postEvents('/collect', events)).toBe('drop');
    });
});
