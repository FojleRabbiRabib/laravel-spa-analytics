import { afterEach, describe, expect, it, vi } from 'vitest';
import fixture from '../../../tests/Fixtures/identify-envelope.json';
import { encodeSignals } from './encode-payload';
import { encryptPayload } from './encrypt-payload';
import type { SignalSet } from './types';

const GOLDEN = '0100010002001800035554430004032000054c696e75780002656e000000015600015200000000';

const hexToBytes = (hex: string): Uint8Array => Uint8Array.from(hex.match(/../g) ?? [], (pair) => parseInt(pair, 16));
const toHex = (bytes: Uint8Array): string => Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
const toBase64 = (bytes: Uint8Array): string => btoa(String.fromCharCode(...bytes));

describe('identify envelope fixture (shared with the PHP decoder)', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('reproduces the fixture body byte for byte from the fixed IV', async () => {
        const iv = hexToBytes(fixture.ivHex);
        vi.spyOn(crypto, 'getRandomValues').mockImplementation(<T extends ArrayBufferView | null>(array: T): T => {
            new Uint8Array((array as ArrayBufferView).buffer).set(iv);

            return array;
        });

        const body = await encryptPayload(hexToBytes(fixture.compressedHex), fixture.key, fixture.nonce);

        expect(toBase64(body)).toBe(fixture.bodyBase64);
    });

    it('inflates to the golden layout that the signal encoder produces for the fixture signals', async () => {
        const stream = new Blob([hexToBytes(fixture.compressedHex) as BlobPart])
            .stream()
            .pipeThrough(new DecompressionStream('gzip'));
        const inflated = new Uint8Array(await new Response(stream).arrayBuffer());

        expect(toHex(inflated)).toBe(GOLDEN);
        expect(toHex(encodeSignals(fixture.signals as SignalSet))).toBe(GOLDEN);
    });
});
