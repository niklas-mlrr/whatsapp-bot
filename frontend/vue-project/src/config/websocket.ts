const defaultScheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();
const defaultHost = typeof window !== 'undefined' ? window.location.hostname : 'localhost';
const basePort = Number(
    import.meta.env.VITE_REVERB_PORT ?? (defaultScheme === 'https' ? '443' : '80')
);
const wsPath = import.meta.env.VITE_REVERB_PATH;

export const websocketConfig = {
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'whatsapp-bot-key',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? defaultHost,
    wsPort: basePort,
    wssPort: basePort,
    wsPath: wsPath && wsPath.length > 0 ? wsPath : undefined,
    forceTLS: defaultScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
} as const;
