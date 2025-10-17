import Echo from 'laravel-echo';
import type { EchoOptions } from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref, onUnmounted, type Ref } from 'vue';
import { useAuthStore } from '@/stores/auth';

// Type definitions for our WebSocket events
type MessageEvent = {
  id: string;
  chat_id: string;
  user_id: string;
  content: string;
  created_at: string;
  updated_at: string;
  status?: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
};

type TypingEvent = {
  user_id: string;
  is_typing: boolean;
  chat_id: string;
};

type ReadReceiptEvent = {
  message_id: string;
  user_id: string;
  chat_id: string;
};

// Extended Window interface for Pusher and Echo
// Note: This matches the declaration in websocket.ts
declare global {
  interface Window {
    Pusher: any;
    Echo: any;
  }
}

// Make Pusher available globally for Laravel Echo
if (!window.Pusher) {
  window.Pusher = Pusher;
}

export const useWebSockets = () => {
  const authStore = useAuthStore();
  const isConnected: Ref<boolean> = ref(false);
  const echo: Ref<Echo<any> | null> = ref(null);
  const socketId: Ref<string | null> = ref(null);
  
  // Store channel instances
  const privateChannels: Map<string, any> = new Map();
  const presenceChannels: Map<string, any> = new Map();
  
  // Store callbacks for different events
  const messageCallbacks: Map<string, (message: MessageEvent) => void> = new Map();
  const typingCallbacks: Map<string, (data: TypingEvent) => void> = new Map();
  const readReceiptCallbacks: Map<string, (data: ReadReceiptEvent) => void> = new Map();
  const connectionCallbacks: Set<() => void> = new Set();

  // Initialize the Echo instance
  const initEcho = async (): Promise<Echo<any> | null> => {
    if (echo.value) {
      return echo.value;
    }

    const token = authStore.token;
    if (!token) {
      console.error('No authentication token found');
      return null;
    }

    try {
      // Configure Echo with Pusher
      const echoConfig: EchoOptions<any> = {
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        wsHost: import.meta.env.VITE_PUSHER_HOST || window.location.hostname,
        wsPort: parseInt(import.meta.env.VITE_PUSHER_PORT || '6001', 10),
        wssPort: parseInt(import.meta.env.VITE_PUSHER_PORT || '6001', 10),
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        auth: {
          headers: {
            Authorization: `Bearer ${token}`,
            'X-Socket-ID': socketId.value || '',
            'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
        },
        authEndpoint: `${import.meta.env.VITE_API_URL}/broadcasting/auth`,
      };

      // Create new Echo instance
      const echoInstance = new Echo(echoConfig);
      echo.value = echoInstance;

      // Set up connection state handling
      const pusher = (echoInstance.connector as any).pusher;
      
      pusher.connection.bind('connected', () => {
        isConnected.value = true;
        socketId.value = pusher.connection.socket_id;
        connectionCallbacks.forEach(callback => callback());
        console.log('WebSocket connected');
      });

      pusher.connection.bind('disconnected', () => {
        isConnected.value = false;
        console.log('WebSocket disconnected');
      });

      pusher.connection.bind('error', (error: any) => {
        console.error('WebSocket error:', error);
        isConnected.value = false;
      });

      return echoInstance;
    } catch (error) {
      console.error('Failed to initialize WebSocket connection:', error);
      isConnected.value = false;
      return null;
    }
  };

  const getSocketId = (): string => {
    return window.Echo?.socketId() || '';
  };

  const disconnect = () => {
    if (echo.value) {
      echo.value.disconnect();
      echo.value = null;
      isConnected.value = false;
    }
  };

  // Auto-disconnect when component unmounts
  onUnmounted(() => {
    disconnect();
  });

  return {
    initEcho,
    disconnect,
    isConnected,
    getSocketId,
  };
};

export default useWebSockets;
