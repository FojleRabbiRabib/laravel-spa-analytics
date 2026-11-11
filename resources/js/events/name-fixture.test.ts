import { describe, expect, it } from 'vitest';
import fixture from '../../../tests/Fixtures/event-names.json';
import { buildApi } from './api';
import type { ClientEvent } from './types';

const accepted = (name: string): boolean => {
    const pushed: ClientEvent[] = [];
    const api = buildApi((event) => pushed.push(event), () => '/current');

    api.track(name);

    return pushed.length === 1;
};

describe('event names (shared with the PHP sanitizer)', () => {
    it.each([...fixture.valid, 'a'.repeat(128)])('accepts %s', (name) => {
        expect(accepted(name)).toBe(true);
    });

    it.each([...fixture.invalid, 'a'.repeat(129)])('rejects %j', (name) => {
        expect(accepted(name)).toBe(false);
    });
});
