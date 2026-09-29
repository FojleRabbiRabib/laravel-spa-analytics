import { identify } from './handshake-client';

const script = document.querySelector<HTMLScriptElement>('script[data-spa-analytics]');
const handshakeUrl = script?.dataset.handshake;
const identifyUrl = script?.dataset.identify;

if (handshakeUrl && identifyUrl) {
    identify(handshakeUrl, identifyUrl).catch(() => undefined);
}
