export interface NavigationEnv {
    history: {
        pushState: (data: unknown, unused: string, url?: string | URL | null) => void;
        replaceState: (data: unknown, unused: string, url?: string | URL | null) => void;
    };
    location: { pathname: string };
    onPopState: (listener: () => void) => void;
    /** Whether a replaceState that changes the pathname is a visit (an Inertia page), asked only when a replaceState has changed the pathname. */
    countsReplace: () => boolean;
}

/**
 * Report each change of pathname made through pushState or the back and forward buttons, and through replaceState
 * when `countsReplace` says it is a visit. A change of only the query string or the hash is not a navigation to
 * another page. Any other replaceState is not reported, but it moves the current path, so the next report starts
 * from the page the visitor was really on.
 */
export const watchNavigation = (env: NavigationEnv, onChange: (from: string, to: string) => void): void => {
    let current = env.location.pathname;

    const check = (): void => {
        const next = env.location.pathname;

        if (next !== current) {
            const previous = current;
            current = next;
            onChange(previous, next);
        }
    };

    const originalPush = env.history.pushState.bind(env.history);
    const originalReplace = env.history.replaceState.bind(env.history);

    env.history.pushState = (data, unused, url): void => {
        originalPush(data, unused, url);
        check();
    };

    env.history.replaceState = (data, unused, url): void => {
        originalReplace(data, unused, url);

        if (env.location.pathname === current) {
            return;
        }

        if (env.countsReplace()) {
            check();
        } else {
            current = env.location.pathname;
        }
    };

    env.onPopState(check);
};
