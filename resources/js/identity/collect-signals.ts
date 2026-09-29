import type { SignalSet } from './types';

const LIMITS = { timezone: 64, platform: 64, languages: 128, webgl: 128 } as const;

export const limitChars = (value: string, max: number): string => Array.from(value).slice(0, max).join('');

const sha256Hex = async (text: string): Promise<string> => {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
};

const attempt = async <T>(work: () => Promise<T> | T, fallback: T): Promise<T> => {
    try {
        return await work();
    } catch {
        return fallback;
    }
};

const canvasHash = (): Promise<string> =>
    attempt(async () => {
        if (typeof document === 'undefined') {
            return '';
        }

        const canvas = document.createElement('canvas');
        canvas.width = 220;
        canvas.height = 60;
        const context = canvas.getContext('2d');

        if (context === null) {
            return '';
        }

        context.textBaseline = 'top';
        context.font = '16px Arial';
        context.fillStyle = '#f60';
        context.fillRect(10, 5, 100, 30);
        context.fillStyle = '#069';
        context.fillText('Laravel SPA Analytics 1.0', 4, 20);
        context.strokeStyle = 'rgba(102, 204, 0, 0.7)';
        context.arc(150, 30, 20, 0, Math.PI * 2);
        context.stroke();

        return sha256Hex(canvas.toDataURL());
    }, '');

const webglInfo = (): { vendor: string; renderer: string } => {
    try {
        if (typeof document === 'undefined') {
            return { vendor: '', renderer: '' };
        }

        const context = document.createElement('canvas').getContext('webgl');
        const debug = context?.getExtension('WEBGL_debug_renderer_info');

        if (!context || !debug) {
            return { vendor: '', renderer: '' };
        }

        return {
            vendor: limitChars(String(context.getParameter(debug.UNMASKED_VENDOR_WEBGL)), LIMITS.webgl),
            renderer: limitChars(String(context.getParameter(debug.UNMASKED_RENDERER_WEBGL)), LIMITS.webgl),
        };
    } catch {
        return { vendor: '', renderer: '' };
    }
};

const audioHash = (): Promise<string> =>
    attempt(async () => {
        if (typeof OfflineAudioContext === 'undefined') {
            return '';
        }

        const context = new OfflineAudioContext(1, 5000, 44100);
        const oscillator = context.createOscillator();
        const compressor = context.createDynamicsCompressor();
        oscillator.type = 'triangle';
        oscillator.frequency.value = 10000;
        oscillator.connect(compressor);
        compressor.connect(context.destination);
        oscillator.start(0);
        const buffer = await context.startRendering();
        const samples = buffer.getChannelData(0).slice(4500, 5000);

        return sha256Hex(Array.from(samples, (sample) => sample.toFixed(6)).join(','));
    }, '');

export const collectSignals = async (): Promise<SignalSet> => {
    const nav = typeof navigator === 'undefined' ? undefined : navigator;
    const scr = typeof screen === 'undefined' ? undefined : screen;
    const gl = webglInfo();
    const memory = (nav as (Navigator & { deviceMemory?: number }) | undefined)?.deviceMemory ?? 0;

    return {
        screenWidth: scr?.width ?? 0,
        screenHeight: scr?.height ?? 0,
        colorDepth: scr?.colorDepth ?? 0,
        timezone: limitChars(Intl.DateTimeFormat().resolvedOptions().timeZone ?? '', LIMITS.timezone),
        hardwareConcurrency: nav?.hardwareConcurrency ?? 0,
        deviceMemory: Math.round(memory * 100),
        platform: limitChars(nav?.platform ?? '', LIMITS.platform),
        languages: limitChars((nav?.languages ?? []).join(','), LIMITS.languages),
        maxTouchPoints: nav?.maxTouchPoints ?? 0,
        webglVendor: gl.vendor,
        webglRenderer: gl.renderer,
        canvasHash: await canvasHash(),
        audioHash: await audioHash(),
    };
};
