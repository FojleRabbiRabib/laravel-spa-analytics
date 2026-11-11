import { parseExtensions } from './events/download';
import { startEvents } from './events/start';
import { identify } from './identity/handshake-client';
import { readScriptConfig } from './script-config';

const running = document.currentScript;
const script =
    running instanceof HTMLScriptElement && running.hasAttribute('data-spa-analytics')
        ? running
        : document.querySelector<HTMLScriptElement>('script[data-spa-analytics]');
const config = readScriptConfig(script, location.href);

if (config.handshake && config.identify) {
    identify(config.handshake, config.identify).catch(() => undefined);
}

if (config.collect) {
    startEvents(config.collect, parseExtensions(config.downloads));
}
