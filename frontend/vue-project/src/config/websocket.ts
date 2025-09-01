export const websocketConfig = {
    broadcaster: 'reverb',
    key: 'whatsapp-bot-key',
    wsHost: '127.0.0.1',
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: false,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
} as const;

