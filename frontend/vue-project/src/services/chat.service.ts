import { ref, onUnmounted } from 'vue';
import apiClient from '@/services/api';

// Define types inline to avoid module resolution issues
type User = {
  id: string;
  name: string;
  phone_number: string;
  avatar_url?: string;
  status?: string;
  last_seen_at?: string;
  is_online?: boolean;
  is_typing?: boolean;
  last_typing_time?: number;
  metadata?: Record<string, any>;
  created_at?: string;
  updated_at?: string;
  isSelected?: boolean;
};

type Message = {
  id: string;
  chat_id: string;
  sender_id: string;
  sender_phone?: string;
  recipient_phone?: string;
  content: string;
  type: 'text' | 'image' | 'video' | 'audio' | 'document' | 'location' | 'contact' | 'sticker' | 'system';
  status: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  created_at: string;
  updated_at: string;
  read_by?: string[];
  isSending?: boolean;
  temp_id?: string;
  media_url?: string;
  media_type?: string;
  media_name?: string;
  media_size?: number;
  media_duration?: number;
  latitude?: number;
  longitude?: number;
  name?: string;
  phone_number?: string;
  quoted_message_id?: string;
  quoted_message?: Message;
  reactions?: {
    [emoji: string]: string[];
  };
  metadata?: Record<string, any>;
};

type Chat = {
  id: string;
  name: string;
  is_group: boolean;
  created_at: string;
  updated_at: string;
  last_message?: string;
  last_message_at?: string;
  unread_count: number;
  is_muted: boolean;
  is_archived: boolean;
  is_blocked: boolean;
  participants: User[];
  admin_ids?: string[];
  metadata?: Record<string, any>;
  avatar_url?: string;
  description?: string;
  isTyping?: boolean;
  isSelected?: boolean;
  isOnline?: boolean;
  lastSeen?: string;
};

declare global {
  interface Window {
    Pusher: any;
    Echo: any;
  }
}

interface TypingUser extends User {
  isTyping: boolean;
  lastTypingTime: number;
}

// Types for WebSocket events
interface TypingEvent {
  user_id: string;
  is_typing: boolean;
}

interface ReadReceiptEvent {
  message_id: string;
  user_id: string;
}

// Type for Echo instance
type EchoInstance = any; // We'll replace 'any' with proper types later

// Type for Pusher connection
type PusherConnection = {
  bind: (event: string, callback: (...args: any[]) => void) => void;
  unbind: (event: string, callback?: (...args: any[]) => void) => void;
};

export const useChatWebSockets = () => {
  // Refs for reactive state
  const currentUser = ref<User | null>(null);
  const echo = ref<EchoInstance | null>(null);
  const isConnected = ref(false);
  
  // Track active channels and listeners
  const activeChannels = new Set<string>();
  const activePrivateChannels = new Set<string>();
  const messageListeners = ref<{ [key: string]: (message: Message) => void }>({});
  const typingListeners = ref<{ [key: string]: (userId: string, isTyping: boolean) => void }>({});
  const readReceiptListeners = ref<{ [key: string]: (messageId: string, userId: string) => void }>({});
  const connectionListeners: Array<() => void> = [];
  
  // Track timeouts for cleanup
  const timeouts = new Map<string, number>();
  
  // Helper to generate unique IDs for listeners
  const generateId = (): string => {
    return Math.random().toString(36).substring(2, 11);
  };
  
  // Set up WebSocket connection handlers
  const setupConnectionHandlers = (echoInstance: EchoInstance): void => {
    if (!echoInstance || !echoInstance.connector || !echoInstance.connector.pusher) {
      console.error('Invalid Echo instance provided');
      return;
    }
    
    const pusher = echoInstance.connector.pusher;
    const connection = pusher.connection;
    
    // Clear any existing connection listeners to avoid duplicates
    connection.unbind('connected');
    connection.unbind('disconnected');
    connection.unbind('error');
    
    // Set up new connection handlers
    connection.bind('connected', () => {
      isConnected.value = true;
      console.log('WebSocket connected');
      
      // Notify all connection listeners
      connectionListeners.forEach(callback => {
        try {
          callback();
        } catch (error) {
          console.error('Error in connection listener:', error);
        }
      });
    });
    
    connection.bind('disconnected', () => {
      isConnected.value = false;
      console.log('WebSocket disconnected');
    });
    
    connection.bind('error', (error: Error) => {
      console.error('WebSocket error:', error);
      isConnected.value = false;
    });
  };
  
  // Initialize Echo with Pusher
  const initEcho = async (): Promise<EchoInstance> => {
    try {
      // Check if Pusher is available
      if (typeof window === 'undefined' || !window.Pusher) {
        // @ts-ignore - Import Pusher dynamically in browser only
        window.Pusher = (await import('pusher-js')).default;
      }
      
      // Get authentication token
      const token = localStorage.getItem('auth_token');
      if (!token) {
        throw new Error('Authentication token not found');
      }
      
      // Import Echo dynamically to avoid SSR issues
      const { default: Echo } = await import('laravel-echo');
      
      // Create new Echo instance
      const echoInstance = new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'eu',
        wsHost: import.meta.env.VITE_WEBSOCKETS_HOST || `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER || 'eu'}.pusher.com`,
        wsPort: parseInt(import.meta.env.VITE_WEBSOCKETS_PORT || '80', 10),
        wssPort: parseInt(import.meta.env.VITE_WEBSOCKETS_PORT || '443', 10),
        forceTLS: (import.meta.env.VITE_WEBSOCKETS_SCHEME || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        auth: {
          headers: {
            'Authorization': `Bearer ${token}`,
            'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
        },
        authEndpoint: `${import.meta.env.VITE_API_URL}/broadcasting/auth`,
      });
      
      // Set up connection handlers
      setupConnectionHandlers(echoInstance);
      
      return echoInstance;
    } catch (error) {
      console.error('Failed to initialize Echo:', error);
      throw error;
    }
  };
  
  const getSocketId = (): string => {
    return echo.value?.socketId() || '';
  };
  
  const disconnectWebSockets = () => {
    if (echo.value) {
      echo.value.disconnect();
      echo.value = null;
      isConnected.value = false;
      activeChannels.clear();
      activePrivateChannels.clear();
    }
  };

  /**
   * Listen for new messages in a chat
   */
  const listenForNewMessages = (chatId: string, callback: (message: Message) => void) => {
    if (!chatId) return () => {};
    
    const channelName = `chat.${chatId}`;
    const listenerId = `${chatId}_${Date.now()}`;
    
    // Store the callback with a unique ID
    messageListeners.value[listenerId] = callback;
    
    // Subscribe to the channel
    const subscribe = async () => {
      try {
        if (!echo.value) {
          await connect();
        }
        
        if (!activeChannels.has(channelName)) {
          echo.value.private(channelName)
            .listen('.message.sent', (data: { message: Message }) => {
              // Notify all listeners for this chat
              Object.values(messageListeners.value).forEach(listener => {
                if (typeof listener === 'function') {
                  listener(data.message);
                }
              });
            });
          
          activeChannels.add(channelName);
        }
      } catch (error) {
        console.error(`Failed to subscribe to chat ${chatId}:`, error);
      }
    };
    
    // Initial subscription
    subscribe();
    
    // Return cleanup function
    return () => {
      delete messageListeners.value[listenerId];
      
      // If no more listeners, unsubscribe from the channel
      if (Object.keys(messageListeners.value).length === 0) {
        leaveChannel(channelName);
      }
    };
  };

  /**
   * Listen for typing indicators in a chat
   */
  const listenForTyping = (chatId: string, callback: (userId: string, isTyping: boolean) => void) => {
    if (!chatId) return () => {};
    
    const channelName = `chat.${chatId}`;
    const listenerId = `typing_${chatId}_${Date.now()}`;
    
    // Store the callback with a unique ID
    typingListeners.value[listenerId] = callback;
    
    // Subscribe to typing events
    const subscribe = async () => {
      try {
        if (!echo.value) {
          await connect();
        }
        
        if (!activeChannels.has(channelName)) {
          // Listen for typing events
          echo.value.private(channelName)
            .listenForWhisper('typing', (data: { userId: string; isTyping: boolean }) => {
              // Notify all typing listeners for this chat
              Object.values(typingListeners.value).forEach(listener => {
                if (typeof listener === 'function') {
                  listener(data.userId, data.isTyping);
                }
              });
            });
          
          activeChannels.add(channelName);
        }
      } catch (error) {
        console.error(`Failed to subscribe to typing events for chat ${chatId}:`, error);
      }
    };
    
    // Initial subscription
    subscribe();
    
    // Return cleanup function
    return () => {
      delete typingListeners.value[listenerId];
    };
  };

  /**
   * Listen for message read receipts
   */
  const listenForReadReceipts = (chatId: string, callback: (messageId: string, userId: string) => void) => {
    const channelName = `chat.${chatId}`;
    
    if (!activeChannels.has(`read.${channelName}`)) {
      echo.value.private(channelName)
        .listen('.message.read', (data: any) => {
          callback(data.message_id, data.user_id);
        });
      
      activeChannels.add(`read.${channelName}`);
    }
  };

  /**
   * Listen for online status changes
   */
  const listenForOnlineStatus = (userId: string, callback: (isOnline: boolean, lastSeen?: string) => void) => {
    const channelName = `user.${userId}`;
    
    if (!activePrivateChannels.has(channelName)) {
      echo.value.private(channelName)
        .listen('.user.online', () => callback(true))
        .listen('.user.offline', (data: any) => callback(false, data.last_seen_at));
      
      activePrivateChannels.add(channelName);
    }
  };

  /**
   * Notify others that the user is typing
   */
  const notifyTyping = async (chatId: string, isTyping: boolean) => {
    if (!chatId) return;
    
    try {
      if (!echo.value) {
        await connect();
      }
      
      const channelName = `chat.${chatId}`;
      
      // Only send typing notification if we're connected
      if (isConnected.value) {
        echo.value.private(channelName)
          .whisper('typing', {
            userId: currentUser.value?.id,
            isTyping,
            timestamp: Date.now()
          });
      }
    } catch (error) {
      console.error('Failed to send typing notification:', error);
    }
  };

  /**
   * Mark messages as read
   */
  const markAsRead = async (chatId: string, messageIds: string[]) => {
    if (!chatId || !messageIds.length) return;
    
    try {
      if (!echo.value) {
        await connect();
      }
      
      // In a real app, you would make an API call to mark messages as read
      await apiClient.post(`/chats/${chatId}/messages/read`, {
        message_ids: messageIds
      });
      
      // Emit read receipt events
      if (isConnected.value) {
        const channelName = `chat.${chatId}`;
        
        messageIds.forEach(messageId => {
          echo.value.private(channelName)
            .whisper('read', {
              message_id: messageId,
              user_id: currentUser.value?.id,
              timestamp: Date.now()
            });
        });
      }
    } catch (error) {
      console.error('Failed to mark messages as read:', error);
      throw error;
    }
  };

  // Connect to WebSocket server
  const connect = async (): Promise<boolean> => {
    try {
      if (!echo.value) {
        echo.value = await initEcho();
      }
      
      if (!isConnected.value) {
        await echo.value.connector.pusher.connection.connect();
        isConnected.value = true;
      }
      
      return true;
    } catch (error) {
      console.error('Error connecting to WebSocket:', error);
      isConnected.value = false;
      throw error;
    }
  };

  // Disconnect from WebSocket server
  const disconnect = () => {
    if (echo.value) {
      try {
        // Leave all channels
        activeChannels.forEach(channel => {
          try {
            echo.value.leave(channel);
          } catch (error) {
            console.error(`Failed to leave channel ${channel}:`, error);
          }
        });
        
        // Disconnect from WebSocket server
        echo.value.disconnect();
        isConnected.value = false;
        activeChannels.clear();
        activePrivateChannels.clear();
        
        // Clear all listeners
        messageListeners.value = {};
        typingListeners.value = {};
        readReceiptListeners.value = {};
        
        console.log('Disconnected from WebSocket server');
      } catch (error) {
        console.error('Error during WebSocket disconnection:', error);
      }
    }
  };
  
  // Leave a specific channel
  const leaveChannel = (channelName: string): void => {
    if (echo.value && activeChannels.has(channelName)) {
      try {
        echo.value.leave(channelName);
        activeChannels.delete(channelName);
      } catch (error) {
        console.error(`Failed to leave channel ${channelName}:`, error);
      }
    }
  };

  // Add a connection listener
  const onConnection = (callback: () => void): (() => void) => {
    connectionListeners.push(callback);
    
    // Return cleanup function
    return () => {
      const index = connectionListeners.indexOf(callback);
      if (index !== -1) {
        connectionListeners.splice(index, 1);
      }
    };
  };

  // Auto-cleanup when component unmounts
  onUnmounted(() => {
    disconnect();
  });

  return {
    // Connection management
    connect,
    disconnect,
    isConnected,
    onConnection,
    
    // Message handling
    listenForNewMessages,
    
    // Typing indicators
    listenForTyping,
    notifyTyping,
    
    // Read receipts
    listenForReadReceipts,
    markAsRead,
    
    // Current user
    currentUser,
    
    // Utility methods
    leaveChannel
  };
};

export default useChatWebSockets;
