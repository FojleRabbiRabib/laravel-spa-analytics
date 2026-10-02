import type { ClientEvent, Properties } from './types';

export interface SpaAnalyticsApi {
    track: (name: string, properties?: Properties) => void;
    goal: (name: string, value?: number, properties?: Properties) => void;
    push: (...calls: unknown[][]) => number;
}

declare global {
    interface Window {
        spaAnalytics?: SpaAnalyticsApi | Array<unknown[]>;
    }
}

const NAME = /^[A-Za-z0-9_.:-]{1,128}$/;

const plainObject = (value: unknown): Properties | undefined =>
    typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Properties) : undefined;

/**
 * The API for `window.spaAnalytics`. A name the server would reject, and properties that are not a plain object, are
 * dropped here instead of travelling to the server.
 */
export const buildApi = (push: (event: ClientEvent, immediate?: boolean) => void, currentPath: () => string): SpaAnalyticsApi => {
    const api: SpaAnalyticsApi = {
        track: (name, properties) => {
            if (!NAME.test(name)) {
                return;
            }

            const clean = plainObject(properties);

            push({ kind: 'event', path: currentPath(), name, ...(clean ? { properties: clean } : {}) });
        },
        goal: (name, value, properties) => {
            if (!NAME.test(name)) {
                return;
            }

            const clean = plainObject(properties);

            push(
                {
                    kind: 'goal',
                    path: currentPath(),
                    name,
                    ...(typeof value === 'number' && Number.isFinite(value) ? { value } : {}),
                    ...(clean ? { properties: clean } : {}),
                },
                true,
            );
        },
        push: (...calls) => {
            replayQueued(calls, api);

            return calls.length;
        },
    };

    return api;
};

/**
 * Run calls made through the array form of the API: `['track', 'name', {…}]` or `['goal', 'name', 9.5]`. Calls
 * queued before the script loaded run in order when it starts, and `push` keeps working afterwards.
 */
export const replayQueued = (queued: unknown, api: SpaAnalyticsApi): void => {
    if (!Array.isArray(queued)) {
        return;
    }

    for (const call of queued) {
        if (!Array.isArray(call) || typeof call[1] !== 'string') {
            continue;
        }

        if (call[0] === 'track') {
            api.track(call[1], call[2] as Properties | undefined);
        } else if (call[0] === 'goal') {
            api.goal(call[1], typeof call[2] === 'number' ? call[2] : undefined, call[3] as Properties | undefined);
        }
    }
};
