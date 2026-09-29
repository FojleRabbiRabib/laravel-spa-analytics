import { describe, expect, it } from 'vitest';
import { collectSignals, limitChars } from './collect-signals';

describe('collectSignals', () => {
    it('returns a complete signal set with safe defaults when browser APIs are missing', async () => {
        const signals = await collectSignals();

        expect(signals.screenWidth).toBe(0);
        expect(signals.webglVendor).toBe('');
        expect(signals.canvasHash).toBe('');
        expect(signals.audioHash).toBe('');
        expect(typeof signals.timezone).toBe('string');
        expect(typeof signals.languages).toBe('string');
    });

    it('keeps every string within the server limits', async () => {
        const signals = await collectSignals();

        expect(signals.timezone.length).toBeLessThanOrEqual(64);
        expect(signals.platform.length).toBeLessThanOrEqual(64);
        expect(signals.languages.length).toBeLessThanOrEqual(128);
        expect(signals.webglVendor.length).toBeLessThanOrEqual(128);
        expect(signals.webglRenderer.length).toBeLessThanOrEqual(128);
    });
});

describe('limitChars', () => {
    it('truncates on a character boundary, not a byte boundary', () => {
        expect(limitChars('abcdef', 3)).toBe('abc');
        expect(limitChars('😀😀😀', 2)).toBe('😀😀');
        expect(limitChars('short', 10)).toBe('short');
    });
});
