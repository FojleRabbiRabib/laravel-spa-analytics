const VERSION = 1;
const encoder = new TextEncoder();

const base64ToBytes = (value: string): Uint8Array<ArrayBuffer> =>
    Uint8Array.from(atob(value), (char) => char.charCodeAt(0));

export const encryptPayload = async (plain: Uint8Array, keyBase64: string, nonce: string): Promise<Uint8Array> => {
    const key = await crypto.subtle.importKey('raw', base64ToBytes(keyBase64), 'AES-GCM', false, ['encrypt']);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const nonceBytes = encoder.encode(nonce);
    const sealed = new Uint8Array(
        await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: nonceBytes }, key, plain as BufferSource),
    );

    const body = new Uint8Array(3 + nonceBytes.length + iv.length + sealed.length);
    body[0] = VERSION;
    body[1] = nonceBytes.length >> 8;
    body[2] = nonceBytes.length & 0xff;
    body.set(nonceBytes, 3);
    body.set(iv, 3 + nonceBytes.length);
    body.set(sealed, 3 + nonceBytes.length + iv.length);

    return body;
};
