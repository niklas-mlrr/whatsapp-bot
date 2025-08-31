// API configuration
export const API_CONFIG = {
  BASE_URL: import.meta.env.VITE_API_URL || '/api',
  WS_URL: import.meta.env.VITE_WS_URL || 'ws://localhost:8080',
  REVERB_KEY: import.meta.env.VITE_REVERB_APP_KEY || 'whatsapp-bot-key',
  REVERB_HOST: import.meta.env.VITE_REVERB_HOST || 'localhost',
  REVERB_PORT: import.meta.env.VITE_REVERB_PORT || '8080',
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
  MESSAGE_SENT: 'message.sent',
  MESSAGE_UPDATED: 'message.updated',
  TYPING: 'user.typing',
  READ_RECEIPT: 'message.read',
};
