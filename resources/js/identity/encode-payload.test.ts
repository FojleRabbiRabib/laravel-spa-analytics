import { describe, expect, it } from 'vitest';
import { encodeSignals, gzip } from './encode-payload';
import type { SignalSet } from './types';

const GOLDEN = '0100010002001800035554430004032000054c696e75780002656e000000015600015200000000';

const sample: SignalSet = {
    screenWidth: 1,
    screenHeight: 2,
    colorDepth: 24,
    timezone: 'UTC',
    hardwareConcurrency: 4,
    deviceMemory: 800,
    platform: 'Linux',
    languages: 'en',
    maxTouchPoints: 0,
    webglVendor: 'V',
    webglRenderer: 'R',
    canvasHash: '',
    audioHash: '',
};

const toHex = (bytes: Uint8Array): string => Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

describe('encodeSignals', () => {
    it('matches the golden byte layout shared with the PHP decoder', () => {
        expect(toHex(encodeSignals(sample))).toBe(GOLDEN);
    });

    it('clamps numbers to u16 and truncates over-long strings to 255 bytes', () => {
        const bytes = encodeSignals({ ...sample, screenWidth: 999999, timezone: 'x'.repeat(400) });

        expect(toHex(bytes.slice(1, 3))).toBe('ffff');
        expect(bytes.length).toBeLessThan(600);
    });
});

describe('gzip', () => {
    it('produces a gzip stream that decompresses back to the input', async () => {
        const input = new TextEncoder().encode('hello identity hello identity');
        const compressed = await gzip(input);

        expect(compressed[0]).toBe(0x1f);
        expect(compressed[1]).toBe(0x8b);

        const stream = new Blob([compressed as BlobPart]).stream().pipeThrough(new DecompressionStream('gzip'));
        const restored = new Uint8Array(await new Response(stream).arrayBuffer());

        expect(new TextDecoder().decode(restored)).toBe('hello identity hello identity');
    });
});
