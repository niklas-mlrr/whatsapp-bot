const defaultScheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'http').toLowerCase();
const defaultHost = typeof window !== 'undefined' ? window.location.hostname : 'localhost';
const basePort = Number(
    import.meta.env.VITE_REVERB_PORT ?? (defaultScheme === 'https' ? '443' : '8080')
);

// Get API base URL for auth endpoint
const apiBaseUrl = import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api';

// For localhost, force non-TLS WebSocket connections
const isLocalhost = (import.meta.env.VITE_REVERB_HOST ?? defaultHost) === 'localhost' || 
                    (import.meta.env.VITE_REVERB_HOST ?? defaultHost) === '127.0.0.1';
// Always use http for localhost, even if scheme is https
const shouldForceTLS = defaultScheme === 'https' && !isLocalhost;

export const websocketConfig = {
    broadcaster: 'pusher',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'whatsapp-bot-key',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? defaultHost,
    wsPort: basePort,
    wssPort: basePort,
    forceTLS: shouldForceTLS,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
    encrypted: false,
    authEndpoint: apiBaseUrl.replace('/api', '') + '/broadcasting/auth',
} as const;
