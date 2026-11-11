export interface ScriptConfig {
    handshake?: string;
    identify?: string;
    collect?: string;
    downloads?: string;
}

interface ConfigSource {
    dataset: Record<string, string | undefined>;
}

/**
 * The url when it points to the same origin as the page, otherwise nothing. The requests carry the XSRF token, so a
 * tag injected into the page must not be able to send them somewhere else.
 */
export const sameOriginUrl = (value: string | undefined, base: string): string | undefined => {
    if (!value) {
        return undefined;
    }

    try {
        return new URL(value, base).origin === new URL(base).origin ? value : undefined;
    } catch {
        return undefined;
    }
};

/**
 * Read the settings the server renders on the script tag, keeping only urls of the page's own origin.
 */
export const readScriptConfig = (script: ConfigSource | null, base: string): ScriptConfig => {
    if (!script) {
        return {};
    }

    const config: ScriptConfig = {};
    const handshake = sameOriginUrl(script.dataset.handshake, base);
    const identify = sameOriginUrl(script.dataset.identify, base);
    const collect = sameOriginUrl(script.dataset.collect, base);

    if (handshake) config.handshake = handshake;
    if (identify) config.identify = identify;
    if (collect) config.collect = collect;
    if (script.dataset.downloads !== undefined) config.downloads = script.dataset.downloads;

    return config;
};
