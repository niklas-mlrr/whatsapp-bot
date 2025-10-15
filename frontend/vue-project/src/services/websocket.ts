import { ref, onUnmounted, type Ref } from 'vue';
import Echo from 'laravel-echo';
import type { EchoOptions } from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';
import { websocketConfig } from '@/config/websocket';

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

type ReactionEvent = {
  message_id: string;
  user: {
    id: string;
    name: string;
  };
  reaction: string;
  added: boolean;
  chat_id: string;
};

// Extended Window interface for Pusher and Echo
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

// Local registries for callbacks and channels
// Maps chatId -> Set of callbacks for each event type
const messageCallbacks: Map<string, Set<(message: MessageEvent) => void>> = new Map();
const typingCallbacks: Map<string, Set<(event: TypingEvent) => void>> = new Map();
const readReceiptCallbacks: Map<string, Set<(event: ReadReceiptEvent) => void>> = new Map();
const reactionCallbacks: Map<string, Set<(event: ReactionEvent) => void>> = new Map();

// Cache for private channels per chat to avoid re-subscribing
const privateChannels: Map<string, any> = new Map();

// Type for our WebSocket service return value
export interface WebSocketService {
  isConnected: boolean;
  socketId: string | null;
  connect(): Promise<boolean>;
  disconnect(): void;
  listenForNewMessages(chatId: string, callback: (message: MessageEvent) => void): () => void;
  listenForTyping(chatId: string, callback: (event: TypingEvent) => void): () => void;
  listenForReadReceipts(chatId: string, callback: (event: ReadReceiptEvent) => void): () => void;
  listenForReactionUpdates(chatId: string, callback: (event: ReactionEvent) => void): () => void;
  notifyTyping(chatId: string, isTyping: boolean): Promise<void>;
  markAsRead(chatId: string, messageIds: string[]): Promise<void>;
  getSocketId(): string | null;
}

// Initialize Echo instance
let echo: Echo<any> | null = null;

export function useWebSocket() {
  const isConnected = ref(false);
  const socketId = ref<string | null>(null);
  const authStore = useAuthStore();

  // Connect to WebSocket server
  const connect = async (): Promise<boolean> => {
    try {
      if (echo) {
        echo.disconnect();
      }

      const token = authStore.token;
      if (!token) {
        console.error('No authentication token available');
        return false;
      }

      echo = new Echo<'reverb'>({
        ...websocketConfig,
        // make enabledTransports mutable to match type expectations
        enabledTransports: ['ws', 'wss'],
        auth: {
          headers: {
            Authorization: `Bearer ${token}`,
          },
        },
      } as any);

      console.log('Connecting to WebSocket:', websocketConfig);

      // Wait for connection
      await new Promise<void>((resolve, reject) => {
        if (!echo) return reject('Echo not initialized');

        echo.connector.pusher.connection.bind('connected', () => {
          isConnected.value = true;
          socketId.value = echo?.socketId() || null;
          resolve();
        });

        echo.connector.pusher.connection.bind('error', (error: any) => {
          reject(error);
        });
      });

      return true;
    } catch (error) {
      console.error('WebSocket connection error:', error);
      return false;
    }
  };

  // Disconnect from WebSocket server
  const disconnect = () => {
    if (echo) {
      echo.disconnect();
      echo = null;
    }
    isConnected.value = false;
    socketId.value = null;
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

  // Listen for reaction updates in a chat
  const listenForReactionUpdates = (
    chatId: string,
    callback: (event: ReactionEvent) => void
  ): (() => void) => {
    if (!reactionCallbacks.has(chatId)) {
      reactionCallbacks.set(chatId, new Set());
    }

    const callbacks = reactionCallbacks.get(chatId)!;
    callbacks.add(callback);

    // Get or create the channel
    let channel = privateChannels.get(chatId);
    if (!channel) {
      channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
      }
    }

    // Always add the listener (Echo handles duplicates)
    if (channel) {
      channel.listen('.message.reaction', (data: any) => {
        const callbacks = reactionCallbacks.get(chatId);
        if (callbacks) {
          callbacks.forEach(cb => cb(data));
        }
      });
    }

    // Return cleanup function
    return () => {
      const callbacks = reactionCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          reactionCallbacks.delete(chatId);
        }
      }
    };
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
    listenForReactionUpdates,
    notifyTyping,
    markAsRead,
    getSocketId
  };
}

export default useWebSocket;
