export interface SignalSet {
    screenWidth: number;
    screenHeight: number;
    colorDepth: number;
    timezone: string;
    hardwareConcurrency: number;
    deviceMemory: number;
    platform: string;
    languages: string;
    maxTouchPoints: number;
    webglVendor: string;
    webglRenderer: string;
    canvasHash: string;
    audioHash: string;
}

export interface Handshake {
    nonce: string;
    key: string;
    expires_at: number;
}
