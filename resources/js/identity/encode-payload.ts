import type { SignalSet } from './types';

const VERSION = 1;
const MAX_STRING_BYTES = 255;
const encoder = new TextEncoder();

const concat = (chunks: Uint8Array[]): Uint8Array => {
    const out = new Uint8Array(chunks.reduce((total, chunk) => total + chunk.length, 0));
    let offset = 0;

    for (const chunk of chunks) {
        out.set(chunk, offset);
        offset += chunk.length;
    }

    return out;
};

const u16 = (value: number): Uint8Array => {
    const clamped = Math.max(0, Math.min(65535, Math.trunc(Number.isFinite(value) ? value : 0)));

    return Uint8Array.of(clamped >> 8, clamped & 0xff);
};

const str = (value: string): Uint8Array => {
    const bytes = encoder.encode(value).slice(0, MAX_STRING_BYTES);

    return concat([u16(bytes.length), bytes]);
};

export const encodeSignals = (s: SignalSet): Uint8Array =>
    concat([
        Uint8Array.of(VERSION),
        u16(s.screenWidth),
        u16(s.screenHeight),
        u16(s.colorDepth),
        str(s.timezone),
        u16(s.hardwareConcurrency),
        u16(s.deviceMemory),
        str(s.platform),
        str(s.languages),
        u16(s.maxTouchPoints),
        str(s.webglVendor),
        str(s.webglRenderer),
        str(s.canvasHash),
        str(s.audioHash),
    ]);

export const gzip = async (data: Uint8Array): Promise<Uint8Array> => {
    const stream = new Blob([data as BlobPart]).stream().pipeThrough(new CompressionStream('gzip'));

    return new Uint8Array(await new Response(stream).arrayBuffer());
};
