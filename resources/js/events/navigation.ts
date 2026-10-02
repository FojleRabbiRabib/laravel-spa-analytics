export interface NavigationEnv {
    history: { pushState: (data: unknown, unused: string, url?: string | URL | null) => void };
    location: { pathname: string };
    onPopState: (listener: () => void) => void;
}

/**
 * Report each change of pathname made through pushState or the back and forward buttons. A change of only the
 * query string or the hash, and replaceState, are not navigations to another page.
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

    const original = env.history.pushState.bind(env.history);

    env.history.pushState = (data, unused, url): void => {
        original(data, unused, url);
        check();
    };

    env.onPopState(check);
};
