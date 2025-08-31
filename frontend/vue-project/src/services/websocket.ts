import { ref, onUnmounted, type Ref } from 'vue';
import Echo from 'laravel-echo';
import type { EchoOptions } from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';
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
declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: typeof Echo;
  }
}

// Make Pusher available globally for Laravel Echo
if (!window.Pusher) {
  window.Pusher = Pusher;
}

// Type for our WebSocket service return value
export interface WebSocketService {
  isConnected: boolean;
  socketId: string | null;
  connect(): Promise<boolean>;
  disconnect(): void;
  listenForNewMessages(chatId: string, callback: (message: MessageEvent) => void): () => void;
  listenForTyping(chatId: string, callback: (event: TypingEvent) => void): () => void;
  listenForReadReceipts(chatId: string, callback: (event: ReadReceiptEvent) => void): () => void;
  notifyTyping(chatId: string, isTyping: boolean): Promise<void>;
  markAsRead(chatId: string, messageIds: string[]): Promise<void>;
  getSocketId(): string | null;
}

export function useWebSocket(): WebSocketService {
  const authStore = useAuthStore();
  const isConnected = ref(false);
  const socketId = ref<string | null>(null);
  let echo: any = null;
  
  // Store channel instances and their callbacks
  const privateChannels = new Map<string, any>();
  const messageCallbacks = new Map<string, Set<(message: MessageEvent) => void>>();
  const typingCallbacks = new Map<string, Set<(event: TypingEvent) => void>>();
  const readReceiptCallbacks = new Map<string, Set<(event: ReadReceiptEvent) => void>>();
  
  // Connect to WebSocket server
  const connect = async (): Promise<boolean> => {
    try {
      if (echo) {
        return true; // Already connected
      }
      
      const token = authStore.token;
      if (!token) {
        console.error('No authentication token available');
        return false;
      }
      
      // Create new Echo instance for Laravel Reverb
      const reverbHost = import.meta.env.VITE_REVERB_HOST || 'localhost';
      const reverbPort = parseInt(import.meta.env.VITE_REVERB_PORT || '8080', 10);
      const reverbAppKey = import.meta.env.VITE_REVERB_APP_KEY || 'whatsapp-bot-key';
      
      console.log('Connecting to WebSocket at:', {
        host: reverbHost,
        port: reverbPort,
        key: reverbAppKey
      });
      
      echo = new Echo({
        broadcaster: 'pusher',
        key: reverbAppKey,
        wsHost: reverbHost,
        wsPort: reverbPort,
        forceTLS: false,
        encrypted: false,
        enabledTransports: ['ws', 'wss'],
        auth: {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
          }
        },
        authEndpoint: '/api/broadcasting/auth'
      });
      
      // Add error handling for Pusher (connecting to Reverb server)
      echo.connector.pusher.connection.bind('error', (err: any) => {
        console.error('Pusher error:', err);
        isConnected.value = false;
      });
      
      // Connection established
      echo.connector.pusher.connection.bind('connected', () => {
        isConnected.value = true;
        socketId.value = echo.socketId();
        console.log('WebSocket connected');
      });
      
      // Connection failed
      echo.connector.pusher.connection.bind('failed', () => {
        console.error('WebSocket connection failed');
        isConnected.value = false;
      });
      
      // Connection disconnected
      echo.connector.pusher.connection.bind('disconnected', () => {
        console.log('WebSocket disconnected');
        isConnected.value = false;
      });
      
      // Set up connection handlers
      const pusher = (echo as any).connector.pusher;
      
      pusher.connection.bind('connected', () => {
        isConnected.value = true;
        socketId.value = pusher.connection.socket_id;
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
      
      return true;
    } catch (error) {
      console.error('Failed to connect to WebSocket:', error);
      isConnected.value = false;
      return false;
    }
  };
  
  // Disconnect from WebSocket server
  const disconnect = (): void => {
    if (echo) {
      try {
        // Leave all channels
        privateChannels.forEach((channel, channelName) => {
          try {
            echo?.leave(channelName);
          } catch (error) {
            console.error(`Failed to leave channel ${channelName}:`, error);
          }
        });
        
        // Disconnect
        echo.disconnect();
        echo = null;
        privateChannels.clear();
        messageCallbacks.clear();
        typingCallbacks.clear();
        readReceiptCallbacks.clear();
        isConnected.value = false;
        socketId.value = null;
        
        console.log('WebSocket disconnected');
      } catch (error) {
        console.error('Error disconnecting WebSocket:', error);
      }
    }
  };
  
  // Listen for new messages in a chat
  const listenForNewMessages = (
    chatId: string, 
    callback: (message: MessageEvent) => void
  ): (() => void) => {
    if (!messageCallbacks.has(chatId)) {
      messageCallbacks.set(chatId, new Set());
    }
    
    const callbacks = messageCallbacks.get(chatId)!;
    callbacks.add(callback);
    
    // Set up the channel if not already done
    if (!privateChannels.has(chatId)) {
      const channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
        
        channel.listen('.message.sent', (data: any) => {
          const callbacks = messageCallbacks.get(chatId);
          if (callbacks) {
            callbacks.forEach(cb => cb(data.message));
          }
        });
      }
    }
    
    // Return cleanup function
    return () => {
      const callbacks = messageCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          messageCallbacks.delete(chatId);
          // Consider leaving the channel if no more callbacks
        }
      }
    };
  };
  
  // Listen for typing indicators in a chat
  const listenForTyping = (
    chatId: string, 
    callback: (event: TypingEvent) => void
  ): (() => void) => {
    if (!typingCallbacks.has(chatId)) {
      typingCallbacks.set(chatId, new Set());
    }
    
    const callbacks = typingCallbacks.get(chatId)!;
    callbacks.add(callback);
    
    // Set up the channel if not already done
    if (!privateChannels.has(chatId)) {
      const channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
        
        channel.listenForWhisper('typing', (data: TypingEvent) => {
          const callbacks = typingCallbacks.get(chatId);
          if (callbacks) {
            callbacks.forEach(cb => cb(data));
          }
        });
      }
    }
    
    // Return cleanup function
    return () => {
      const callbacks = typingCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          typingCallbacks.delete(chatId);
        }
      }
    };
  };
  
  // Listen for read receipts in a chat
  const listenForReadReceipts = (
    chatId: string, 
    callback: (event: ReadReceiptEvent) => void
  ): (() => void) => {
    if (!readReceiptCallbacks.has(chatId)) {
      readReceiptCallbacks.set(chatId, new Set());
    }
    
    const callbacks = readReceiptCallbacks.get(chatId)!;
    callbacks.add(callback);
    
    // Set up the channel if not already done
    if (!privateChannels.has(chatId)) {
      const channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
        
        channel.listen('.message.read', (data: any) => {
          const callbacks = readReceiptCallbacks.get(chatId);
          if (callbacks) {
            callbacks.forEach(cb => cb(data));
          }
        });
      }
    }
    
    // Return cleanup function
    return () => {
      const callbacks = readReceiptCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          readReceiptCallbacks.delete(chatId);
        }
      }
    };
  };
  
  // Notify others that user is typing
  const notifyTyping = async (chatId: string, isTyping: boolean): Promise<void> => {
    if (!echo || !isConnected.value) {
      console.error('WebSocket not connected');
      return;
    }
    
    try {
      const channel = privateChannels.get(chatId) || echo.private(`chat.${chatId}`);
      if (!privateChannels.has(chatId)) {
        privateChannels.set(chatId, channel);
      }
      
      await channel.whisper('typing', {
        user_id: authStore.user?.id,
        is_typing: isTyping,
        chat_id: chatId
      });
    } catch (error) {
      console.error('Error sending typing indicator:', error);
    }
  };
  
  // Mark messages as read
  const markAsRead = async (chatId: string, messageIds: string[]): Promise<void> => {
    if (!echo || !isConnected.value) {
      console.error('WebSocket not connected');
      return;
    }
    
    try {
      const channel = privateChannels.get(chatId) || echo.private(`chat.${chatId}`);
      if (!privateChannels.has(chatId)) {
        privateChannels.set(chatId, channel);
      }
      
      await channel.whisper('read', {
        message_ids: messageIds,
        user_id: authStore.user?.id,
        chat_id: chatId
      });
    } catch (error) {
      console.error('Error marking messages as read:', error);
    }
  };
  
  // Get current socket ID
  const getSocketId = (): string | null => {
    return socketId.value;
  };
  
  // Clean up on component unmount
  onUnmounted(() => {
    disconnect();
  });
  
  return {
    isConnected: isConnected.value,
    socketId: socketId.value,
    connect,
    disconnect,
    listenForNewMessages,
    listenForTyping,
    listenForReadReceipts,
    notifyTyping,
    markAsRead,
    getSocketId
  };
}

export default useWebSocket;
