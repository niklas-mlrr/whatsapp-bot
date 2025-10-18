export const websocketConfig = {
    broadcaster: 'reverb',
    key: 'whatsapp-bot-key',
    wsHost: 'lukas-whatsapp.cloud',
    wsPort: 443,
    wssPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
} as const;
