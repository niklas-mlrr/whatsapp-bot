// API configuration
const resolveBaseUrl = (): string => {
  const rawUrl = import.meta.env.VITE_API_URL?.trim();

  if (rawUrl) {
    try {
      const url = new URL(
        rawUrl,
        typeof window !== 'undefined' ? window.location.origin : 'http://localhost'
      );

      if (url.hostname === 'localhost') {
        if (!url.port) {
          url.port = url.protocol === 'https:' ? '443' : '8000';
        }

        if (url.protocol === 'https:' && url.port === '443') {
          url.protocol = 'http:';
          url.port = '8000';
        }
      }

      url.pathname = url.pathname.replace(/\/+$/, '');
      return `${url.origin}${url.pathname || ''}` || '/api';
    } catch (error) {
      console.warn('Failed to parse VITE_API_URL, using raw value instead.', error);
      return rawUrl;
    }
  }

  if (typeof window !== 'undefined') {
    return `${window.location.origin.replace(/\/+$/, '')}/api`;
  }

  return '/api';
};

const resolveWsUrl = (): string => {
  const rawUrl = import.meta.env.VITE_WS_URL?.trim();

  if (rawUrl) {
    try {
      const url = new URL(rawUrl, 'ws://localhost');

      if (url.hostname === 'localhost') {
        if (!url.port) {
          url.port = url.protocol === 'wss:' ? '443' : '8080';
        }

        // Force WSS to WS for localhost
        if (url.protocol === 'wss:') {
          url.protocol = 'ws:';
          // If port was 443 (default HTTPS), change to 8080
          if (url.port === '443') {
            url.port = '8080';
          }
        }
      }

      url.pathname = url.pathname.replace(/\/+$/, '');
      return `${url.origin}${url.pathname || ''}`;
    } catch (error) {
      console.warn('Failed to parse VITE_WS_URL, using raw value instead.', error);
      return rawUrl;
    }
  }

  return 'ws://localhost:8080';
};

export const API_CONFIG = {
  BASE_URL: resolveBaseUrl(),
  WS_URL: resolveWsUrl(),
  REVERB_KEY: import.meta.env.VITE_REVERB_APP_KEY || 'whatsapp-bot-key',
  REVERB_HOST: import.meta.env.VITE_REVERB_HOST || 'localhost',
  REVERB_PORT: import.meta.env.VITE_REVERB_PORT || '8080',
};

// Debug logging
console.log('[API_CONFIG] Resolved configuration:', {
  BASE_URL: API_CONFIG.BASE_URL,
  WS_URL: API_CONFIG.WS_URL,
  VITE_API_URL: import.meta.env.VITE_API_URL,
  VITE_WS_URL: import.meta.env.VITE_WS_URL,
});

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
