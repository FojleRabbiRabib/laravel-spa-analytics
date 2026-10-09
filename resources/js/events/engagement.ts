export const IDLE_MS = 15_000;
export const MAX_SECONDS = 1_800;

export interface EngagementTracker {
    activity: (at: number) => void;
    visible: (at: number) => void;
    hidden: (at: number) => void;
    take: (at: number) => number;
    reset: (at: number) => void;
}

/**
 * Count the time a visitor is actually on the page: the tab is visible and focused and something was done within the
 * last 15 seconds. Time after the last action stops counting once that window has passed, and starts again with the
 * next action. All times are passed in so the count does not depend on a clock.
 */
export const createEngagementTracker = (start: number, visible: boolean): EngagementTracker => {
    let total = 0;
    let since: number | null = visible ? start : null;
    let activeUntil = start + IDLE_MS;

    const account = (at: number): void => {
        if (since === null) {
            return;
        }

        const end = Math.min(at, activeUntil);

        if (end > since) {
            total += end - since;
        }

        since = Math.max(since, at);
    };

    const activity = (at: number): void => {
        if (since === null) {
            return;
        }

        account(at);
        activeUntil = at + IDLE_MS;
    };

    return {
        activity,
        visible: (at: number): void => {
            if (since === null) {
                since = at;
                activeUntil = at + IDLE_MS;

                return;
            }

            activity(at);
        },
        hidden: (at: number): void => {
            account(at);
            since = null;
        },
        take: (at: number): number => {
            account(at);

            const whole = Math.floor(total / 1000);

            if (whole < 1) {
                return 0;
            }

            total -= whole * 1000;

            return Math.min(whole, MAX_SECONDS);
        },
        reset: (at: number): void => {
            total = 0;
            since = since === null ? null : at;
            activeUntil = at + IDLE_MS;
        },
    };
};
