export type Properties = Record<string, unknown>;

export type ClientEvent =
    | { kind: 'pageview'; path: string; referrer?: string }
    | { kind: 'outbound'; path: string; url: string }
    | { kind: 'download'; path: string; url: string }
    | { kind: 'scroll'; path: string; percent: number }
    | { kind: 'event'; path: string; name: string; properties?: Properties }
    | { kind: 'goal'; path: string; name: string; value?: number; properties?: Properties };

export type PayloadEvent = ClientEvent & { age: number };

export type SendBatch = (events: PayloadEvent[]) => Promise<void>;
