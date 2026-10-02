import { startEvents } from './events/start';
import { identify } from './identity/handshake-client';

const script = document.querySelector<HTMLScriptElement>('script[data-spa-analytics]');
const handshakeUrl = script?.dataset.handshake;
const identifyUrl = script?.dataset.identify;
const collectUrl = script?.dataset.collect;

if (handshakeUrl && identifyUrl) {
    identify(handshakeUrl, identifyUrl).catch(() => undefined);
}

if (collectUrl) {
    startEvents(collectUrl);
}
