/**
 * The header Laravel expects on a same-origin post, read from the XSRF-TOKEN cookie.
 */
export const xsrfHeader = (): Record<string, string> => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    const token = match?.[1];

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
};
