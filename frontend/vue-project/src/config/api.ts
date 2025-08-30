// API configuration
export const API_CONFIG = {
  BASE_URL: import.meta.env.VITE_API_URL || '/api',
  WS_URL: import.meta.env.VITE_WS_URL || 'ws://localhost:8080',
  PUSHER_KEY: import.meta.env.VITE_PUSHER_APP_KEY || 'whatsapp-bot-key',
  PUSHER_CLUSTER: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
};

// API endpoints
export const API_ENDPOINTS = {
  LOGIN: '/login',
  LOGOUT: '/logout',
  ME: '/me',
  CHATS: '/chats',
  MESSAGES: '/messages',
  UPLOAD: '/upload',
  BROADCAST_AUTH: '/broadcasting/auth',
};

// WebSocket events
export const WS_EVENTS = {
  MESSAGE_SENT: 'MessageSent',
  MESSAGE_UPDATED: 'MessageUpdated',
  TYPING: 'UserTyping',
  READ_RECEIPT: 'ReadReceipt',
};
