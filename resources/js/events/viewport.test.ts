import { describe, expect, it } from 'vitest';
import { viewportWidth } from './viewport';

describe('viewportWidth', () => {
    it('reports the width in whole pixels', () => {
        expect(viewportWidth(1280)).toBe(1280);
        expect(viewportWidth(390.4)).toBe(390);
    });

    it('reports nothing for a width that is not usable', () => {
        expect(viewportWidth(0)).toBeNull();
        expect(viewportWidth(-5)).toBeNull();
        expect(viewportWidth(Number.NaN)).toBeNull();
        expect(viewportWidth(Number.POSITIVE_INFINITY)).toBeNull();
    });
});
