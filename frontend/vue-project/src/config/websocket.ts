const defaultScheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'http').toLowerCase();
const defaultHost = typeof window !== 'undefined' ? window.location.hostname : 'localhost';
const basePort = Number(
    import.meta.env.VITE_REVERB_PORT ?? (defaultScheme === 'https' ? '443' : '8080')
);
const wsPath = import.meta.env.VITE_REVERB_PATH;

// For localhost, force non-TLS WebSocket connections
const isLocalhost = (import.meta.env.VITE_REVERB_HOST ?? defaultHost) === 'localhost' || 
                    (import.meta.env.VITE_REVERB_HOST ?? defaultHost) === '127.0.0.1';
const shouldForceTLS = defaultScheme === 'https' && !isLocalhost;

export const websocketConfig = {
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'whatsapp-bot-key',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? defaultHost,
    wsPort: basePort,
    wssPort: basePort,
    wsPath: wsPath && wsPath.length > 0 ? wsPath : undefined,
    forceTLS: shouldForceTLS,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
} as const;
