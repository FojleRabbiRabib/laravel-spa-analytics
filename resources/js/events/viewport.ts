/**
 * The window width to report, in whole pixels including the scrollbar like a CSS media query. Null when the browser
 * gives no usable number, so nothing is sent.
 */
export const viewportWidth = (innerWidth: number): number | null => {
    const width = Math.round(innerWidth);

    return Number.isFinite(width) && width > 0 ? width : null;
};
