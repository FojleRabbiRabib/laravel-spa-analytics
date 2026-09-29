import { describe, expect, it } from 'vitest';
import { encryptPayload } from './encrypt-payload';

const keyBytes = crypto.getRandomValues(new Uint8Array(32));
const keyBase64 = btoa(String.fromCharCode(...keyBytes));

describe('encryptPayload', () => {
    it('produces version | nonce length | nonce | iv | ciphertext+tag that decrypts with the nonce as AAD', async () => {
        const plain = new TextEncoder().encode('hello identity');
        const nonce = 'abc.def';

        const body = await encryptPayload(plain, keyBase64, nonce);

        expect(body[0]).toBe(1);
        expect(new DataView(body.buffer, body.byteOffset).getUint16(1)).toBe(nonce.length);
        expect(new TextDecoder().decode(body.slice(3, 3 + nonce.length))).toBe(nonce);

        const ivStart = 3 + nonce.length;
        const iv = body.slice(ivStart, ivStart + 12);
        const cipher = body.slice(ivStart + 12);
        const key = await crypto.subtle.importKey('raw', keyBytes, 'AES-GCM', false, ['decrypt']);
        const decrypted = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv, additionalData: new TextEncoder().encode(nonce) },
            key,
            cipher,
        );

        expect(new TextDecoder().decode(decrypted)).toBe('hello identity');
        expect(cipher.length).toBe(plain.length + 16);
    });

    it('uses a fresh IV each call', async () => {
        const plain = new Uint8Array([1, 2, 3]);
        const a = await encryptPayload(plain, keyBase64, 'n');
        const b = await encryptPayload(plain, keyBase64, 'n');

        expect(a).not.toEqual(b);
    });
});
