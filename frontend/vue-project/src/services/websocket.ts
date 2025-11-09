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

type MessageEditedEvent = {
  message_id: string;
  chat_id: string;
  content: string;
  edited_at: string;
  user: {
    id: string;
    name: string;
  };
};

type MessageDeletedEvent = {
  message_id: string;
  chat_id: string;
  user: {
    id: string;
    name: string;
  };
  for_everyone: boolean;
  deleted_at: string;
};

type PollUpdateEvent = {
  message_id: string;
  chat_id: string;
  poll_votes: any[];
  metadata: any;
};

type ContactUpdateEvent = {
  contact: {
    id: number;
    phone: string;
    name: string;
    profile_picture_url: string | null;
    bio: string | null;
    updated_at: string;
  };
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
const messageEditedCallbacks: Map<string, Set<(event: MessageEditedEvent) => void>> = new Map();
const messageDeletedCallbacks: Map<string, Set<(event: MessageDeletedEvent) => void>> = new Map();
const pollUpdateCallbacks: Map<string, Set<(event: PollUpdateEvent) => void>> = new Map();
const contactUpdateCallbacks: Set<(event: ContactUpdateEvent) => void> = new Set();

// Cache for private channels per chat to avoid re-subscribing
const privateChannels: Map<string, any> = new Map();

// Track which event listeners have been set up for each channel
const channelListenersSetup: Map<string, Set<string>> = new Map();

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
  listenForMessageEdited(chatId: string, callback: (event: MessageEditedEvent) => void): () => void;
  listenForMessageDeleted(chatId: string, callback: (event: MessageDeletedEvent) => void): () => void;
  listenForPollUpdates(chatId: string, callback: (event: PollUpdateEvent) => void): () => void;
  listenForContactUpdates(callback: (event: ContactUpdateEvent) => void): () => void;
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
  let retryTimeoutId: number | null = null;

  // Connect to WebSocket server
  const connect = async (retryCount = 0, maxRetries = 3): Promise<boolean> => {
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

      // Wait for connection with timeout
      await new Promise<void>((resolve, reject) => {
        if (!echo) return reject('Echo not initialized');

        const timeoutId = setTimeout(() => {
          reject(new Error('WebSocket connection timeout'));
        }, 10000); // 10 second timeout

        echo.connector.pusher.connection.bind('connected', () => {
          clearTimeout(timeoutId);
          isConnected.value = true;
          socketId.value = echo?.socketId() || null;
          resolve();
        });

        echo.connector.pusher.connection.bind('error', (error: any) => {
          clearTimeout(timeoutId);
          console.error('WebSocket connection error:', error);
          reject(error);
        });

        echo.connector.pusher.connection.bind('unavailable', () => {
          clearTimeout(timeoutId);
          console.warn('WebSocket connection unavailable - server may not be running');
          // Don't reject immediately, give it time to retry
        });

        echo.connector.pusher.connection.bind('disconnected', () => {
          // Connection lost
        });

        echo.connector.pusher.connection.bind('failed', () => {
          clearTimeout(timeoutId);
          console.error('WebSocket connection failed');
          reject(new Error('WebSocket connection failed - server may not be running'));
        });
      });

      return true;
    } catch (error) {
      console.error('WebSocket connection error:', error);
      
      // Retry logic
      if (retryCount < maxRetries) {
        const delay = Math.min(1000 * Math.pow(2, retryCount), 10000); // Exponential backoff, max 10s
        
        return new Promise((resolve) => {
          retryTimeoutId = setTimeout(async () => {
            const result = await connect(retryCount + 1, maxRetries);
            resolve(result);
          }, delay);
        });
      }
      
      // Return false instead of throwing, allowing the app to continue
      return false;
    }
  };

  // Disconnect from WebSocket server
  const disconnect = () => {
    // Clear any pending retry attempts
    if (retryTimeoutId) {
      clearTimeout(retryTimeoutId);
      retryTimeoutId = null;
    }
    
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

    // Get or create the channel
    let channel = privateChannels.get(chatId);
    if (!channel) {
      channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
      }
    }

    // Set up listeners only if not already done for this channel
    if (channel) {
      if (!channelListenersSetup.has(chatId)) {
        channelListenersSetup.set(chatId, new Set());
      }
      
      const listenersSetup = channelListenersSetup.get(chatId)!;
      
      if (!listenersSetup.has('new-messages')) {
        channel.listen('.message.sent', (data: any) => {
          const callbacks = messageCallbacks.get(chatId);
          if (callbacks) {
            callbacks.forEach(cb => cb(data.message));
          }
        });
        listenersSetup.add('new-messages');
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

        // Listen for broadcast typing events from backend
        channel.listen('.user.typing', (data: any) => {
          const callbacks = typingCallbacks.get(chatId);
          if (callbacks) {
            // Extract user_id from the user object if needed
            const typingEvent: TypingEvent = {
              user_id: data.user?.id || data.user_id,
              is_typing: data.is_typing,
              chat_id: data.chat_id || chatId
            };
            callbacks.forEach(cb => cb(typingEvent));
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

    // Get or create the channel
    let channel = privateChannels.get(chatId);
    if (!channel) {
      channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
      }
    }

    // Set up listeners only if not already done for this channel
    if (channel) {
      if (!channelListenersSetup.has(chatId)) {
        channelListenersSetup.set(chatId, new Set());
      }
      
      const listenersSetup = channelListenersSetup.get(chatId)!;
      
      if (!listenersSetup.has('read-receipts')) {
        // Listen for message status updates (includes read receipts)
        channel.listen('.message-status-updated', (data: any) => {
          const callbacks = readReceiptCallbacks.get(chatId);
          if (callbacks) {
            callbacks.forEach(cb => cb(data));
          } else {
            console.warn('[WebSocket] No callbacks registered for read receipts in chat:', chatId);
          }
        });
        
        // Also listen for legacy .message.read events for backward compatibility
        channel.listen('.message.read', (data: any) => {
          const callbacks = readReceiptCallbacks.get(chatId);
          if (callbacks) {
            callbacks.forEach(cb => cb(data));
          }
        });
        
        listenersSetup.add('read-receipts');
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
      // Silently skip if not connected - typing indicators are not critical
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
      // Silently skip if not connected
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

  // Get socket ID
  const getSocketId = (): string | null => {
    return socketId.value;
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

      // Also listen to direct websocket service events (non-Laravel broadcast)
      channel.listen('message.reaction_updated', (data: any) => {
        // Normalize payload to ReactionEvent shape expected by UI
        const normalized: ReactionEvent = {
          message_id: String(data.message_id ?? data.messageId ?? ''),
          chat_id: String(data.chat_id ?? chatId),
          user: {
            id: String(data.user?.id ?? data.user_id ?? ''),
            name: String(data.user?.name ?? data.user_name ?? ''),
          },
          reaction: String(data.reaction ?? ''),
          added: Boolean(data.reaction)
        };

        const callbacks = reactionCallbacks.get(chatId);
        if (callbacks) {
          callbacks.forEach(cb => cb(normalized));
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

  // Listen for message edited events
  const listenForMessageEdited = (
    chatId: string,
    callback: (event: MessageEditedEvent) => void
  ): (() => void) => {
    if (!messageEditedCallbacks.has(chatId)) {
      messageEditedCallbacks.set(chatId, new Set());
    }

    const callbacks = messageEditedCallbacks.get(chatId)!;
    callbacks.add(callback);

    // Get or create the channel
    let channel = privateChannels.get(chatId);
    if (!channel) {
      channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
      }
    }

    // Add the listener
    if (channel) {
      channel.listen('.message.edited', (data: any) => {
        const callbacks = messageEditedCallbacks.get(chatId);
        if (callbacks) {
          callbacks.forEach(cb => cb(data));
        }
      });
    }

    // Return cleanup function
    return () => {
      const callbacks = messageEditedCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          messageEditedCallbacks.delete(chatId);
        }
      }
    };
  };

  // Listen for message deleted events
  const listenForMessageDeleted = (
    chatId: string,
    callback: (event: MessageDeletedEvent) => void
  ): (() => void) => {
    if (!messageDeletedCallbacks.has(chatId)) {
      messageDeletedCallbacks.set(chatId, new Set());
    }
    messageDeletedCallbacks.get(chatId)!.add(callback);

    // Get or create the channel
    let channel = privateChannels.get(chatId);
    if (!channel) {
      channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
      }
    }

    // Add the listener
    if (channel) {
      channel.listen('.message.deleted', (data: any) => {
        const callbacks = messageDeletedCallbacks.get(chatId);
        if (callbacks) {
          callbacks.forEach(cb => cb(data));
        }
      });
    }

    // Return cleanup function
    return () => {
      const callbacks = messageDeletedCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          messageDeletedCallbacks.delete(chatId);
        }
      }
    };
  };

  // Listen for poll update events
  const listenForPollUpdates = (
    chatId: string,
    callback: (event: PollUpdateEvent) => void
  ): (() => void) => {
    if (!pollUpdateCallbacks.has(chatId)) {
      pollUpdateCallbacks.set(chatId, new Set());
    }
    pollUpdateCallbacks.get(chatId)!.add(callback);

    // Get or create the channel
    let channel = privateChannels.get(chatId);
    if (!channel) {
      channel = echo?.private(`chat.${chatId}`);
      if (channel) {
        privateChannels.set(chatId, channel);
      }
    }

    // Add the listener
    if (channel) {
      channel.listen('.message.poll_updated', (data: any) => {
        const callbacks = pollUpdateCallbacks.get(chatId);
        if (callbacks) {
          callbacks.forEach(cb => cb(data));
        }
      });
    }

    // Return cleanup function
    return () => {
      const callbacks = pollUpdateCallbacks.get(chatId);
      if (callbacks) {
        callbacks.delete(callback);
        if (callbacks.size === 0) {
          pollUpdateCallbacks.delete(chatId);
        }
      }
    };
  };

  // Listen for contact updates (global channel)
  const listenForContactUpdates = (
    callback: (event: ContactUpdateEvent) => void
  ): (() => void) => {
    contactUpdateCallbacks.add(callback);

    // Subscribe to global contacts channel
    if (echo && !privateChannels.has('contacts')) {
      const channel = echo.channel('contacts');
      privateChannels.set('contacts', channel);

      // Listen for contact.updated events
      channel.listen('.contact.updated', (data: any) => {
        contactUpdateCallbacks.forEach(cb => cb(data));
      });
    }

    // Return cleanup function
    return () => {
      contactUpdateCallbacks.delete(callback);
      if (contactUpdateCallbacks.size === 0) {
        // Optionally leave the channel if no more callbacks
        const channel = privateChannels.get('contacts');
        if (channel) {
          echo?.leave('contacts');
          privateChannels.delete('contacts');
        }
      }
    };
  };

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
    listenForMessageEdited,
    listenForMessageDeleted,
    listenForPollUpdates,
    listenForContactUpdates,
    notifyTyping,
    markAsRead,
    getSocketId
  };
}

export default useWebSocket;
