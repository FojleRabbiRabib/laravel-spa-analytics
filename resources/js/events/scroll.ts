export const MILESTONES = [25, 50, 75, 100] as const;

export interface ScrollTracker {
    update: (depth: number) => void;
    reset: () => void;
}

/**
 * Report each milestone once per page. Depth is how far down the page the bottom edge of the window has reached, as
 * a percentage, and only ever comes from a scroll event, so a page that fits the window reports nothing.
 */
export const createScrollTracker = (emit: (percent: number) => void): ScrollTracker => {
    let reached = 0;

    return {
        update: (depth: number): void => {
            for (const milestone of MILESTONES) {
                if (milestone > reached && depth >= milestone) {
                    reached = milestone;
                    emit(milestone);
                }
            }
        },
        reset: (): void => {
            reached = 0;
        },
    };
};

/**
 * The percentage of the page above the bottom edge of the window; 0 when the page has no height.
 */
export const scrollDepth = (scrollTop: number, viewportHeight: number, pageHeight: number): number =>
    pageHeight > 0 ? Math.min(100, ((scrollTop + viewportHeight) / pageHeight) * 100) : 0;
