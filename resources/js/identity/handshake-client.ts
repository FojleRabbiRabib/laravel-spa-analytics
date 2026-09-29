import { collectSignals } from './collect-signals';
import { encodeSignals, gzip } from './encode-payload';
import { encryptPayload } from './encrypt-payload';
import type { Handshake } from './types';

const SESSION_FLAG = 'spa_analytics_identified';

const xsrfHeader = (): Record<string, string> => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    const token = match?.[1];

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
};

const alreadyIdentified = (): boolean => {
    try {
        return sessionStorage.getItem(SESSION_FLAG) === '1';
    } catch {
        return false;
    }
};

const markIdentified = (): void => {
    try {
        sessionStorage.setItem(SESSION_FLAG, '1');
    } catch {
        return;
    }
};

export const identify = async (handshakeUrl: string, identifyUrl: string): Promise<void> => {
    if (alreadyIdentified()) {
        return;
    }

    const handshakeResponse = await fetch(handshakeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...xsrfHeader() },
    });

    if (!handshakeResponse.ok) {
        return;
    }

    const handshake = (await handshakeResponse.json()) as Handshake;
    const plain = await gzip(encodeSignals(await collectSignals()));
    const body = await encryptPayload(plain, handshake.key, handshake.nonce);

    const response = await fetch(identifyUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/octet-stream', ...xsrfHeader() },
        body: body as BodyInit,
    });

    if (response.ok) {
        markIdentified();
    }
};
