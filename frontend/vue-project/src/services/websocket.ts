/**
 * Unified WebSocket service using Laravel Echo
 * Consolidates connection management and chat operations.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref, onUnmounted, type Ref } from 'vue';
import { websocketConfig } from '@/config/websocket';
import type { MessageEvent, TypingEvent, ReadReceiptEvent, EchoInstance, PrivateChannel } from '@/types';

// Make Pusher available globally for Laravel Echo
if (typeof window !== 'undefined' && !window.Pusher) {
  window.Pusher = Pusher;
}

// Singleton state
let echoInstance: EchoInstance | null = null;
const isConnected: Ref<boolean> = ref(false);
const socketId: Ref<string | null> = ref(null);

// Channel tracking
const activeChannels = new Set<string>();
const initializedChannels = new Set<string>();
const messageCallbacks = new Map<string, (message: MessageEvent) => void>();
const typingCallbacks = new Map<string, (data: TypingEvent) => void>();
const readReceiptCallbacks = new Map<string, (data: ReadReceiptEvent) => void>();
const connectionCallbacks = new Set<() => void>();

// Callback types for additional events
type ReactionEvent = {
  message_id: string;
  chat_id: string;
  user: { id: string; name: string; avatar_url?: string };
  reaction: string;
  added: boolean;
  timestamp: string;
};
type MessageEditedCallback = (message: MessageEvent) => void;
type MessageDeletedCallback = (messageId: string) => void;
type PollUpdateCallback = (pollId: string, votes: unknown[]) => void;

const reactionCallbacks = new Map<string, (event: unknown) => void>();
const messageEditedCallbacks = new Map<string, MessageEditedCallback>();
const messageDeletedCallbacks = new Map<string, MessageDeletedCallback>();
const pollUpdateCallbacks = new Map<string, PollUpdateCallback>();

/**
 * Initialize the Echo instance and connect to WebSocket server.
 */
async function initEcho(): Promise<EchoInstance | null> {
  if (echoInstance) {
    return echoInstance;
  }

  const token = localStorage.getItem('token');
  if (!token) {
    console.error('No authentication token found');
    return null;
  }

  try {
    const echo = new Echo({
      broadcaster: websocketConfig.broadcaster,
      key: websocketConfig.key,
      wsHost: websocketConfig.wsHost,
      wsPort: websocketConfig.wsPort,
      wssPort: websocketConfig.wssPort,
      forceTLS: websocketConfig.forceTLS,
      enabledTransports: [...websocketConfig.enabledTransports] as ('ws' | 'wss')[],
      disableStats: websocketConfig.disableStats,
      cluster: websocketConfig.cluster,
      auth: {
        headers: {
          Authorization: `Bearer ${token}`,
          'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
      },
      authEndpoint: websocketConfig.authEndpoint,
    }) as unknown as EchoInstance;

    echoInstance = echo;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const pusher = (echo as unknown as { connector: { pusher: { connection: { bind: (event: string, cb: (...args: unknown[]) => void) => void; socket_id: string } } } }).connector.pusher;

    pusher.connection.bind('connected', () => {
      isConnected.value = true;
      socketId.value = pusher.connection.socket_id;
      connectionCallbacks.forEach(callback => {
        try {
          callback();
        } catch (error) {
          console.error('Error in connection callback:', error);
        }
      });
    });

    pusher.connection.bind('disconnected', () => {
      isConnected.value = false;
      socketId.value = null;
    });

    pusher.connection.bind('error', (error: unknown) => {
      console.error('WebSocket error:', error);
      isConnected.value = false;
    });

    return echo;
  } catch (error) {
    console.error('Failed to initialize WebSocket connection:', error);
    isConnected.value = false;
    return null;
  }
}

/**
 * Connect to WebSocket server.
 */
async function connect(): Promise<boolean> {
  try {
    if (!echoInstance) {
      echoInstance = await initEcho();
    }

    if (!isConnected.value && echoInstance) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (echoInstance as unknown as { connector: { pusher: { connection: { connect: () => void } } } }).connector.pusher.connection.connect();
      isConnected.value = true;
    }

    return true;
  } catch (error) {
    console.error('Error connecting to WebSocket:', error);
    isConnected.value = false;
    throw error;
  }
}

/**
 * Disconnect from WebSocket server.
 */
function disconnect(): void {
  if (echoInstance) {
    activeChannels.forEach(channel => {
      try {
        echoInstance?.leave(channel);
      } catch (error) {
        console.error(`Failed to leave channel ${channel}:`, error);
      }
    });

    echoInstance.disconnect();
    echoInstance = null;
    isConnected.value = false;
    activeChannels.clear();
    initializedChannels.clear();
    messageCallbacks.clear();
    typingCallbacks.clear();
    readReceiptCallbacks.clear();
    reactionCallbacks.clear();
    messageEditedCallbacks.clear();
    messageDeletedCallbacks.clear();
    pollUpdateCallbacks.clear();
  }
}

/**
 * Get current socket ID.
 */
function getSocketId(): string {
  return echoInstance?.socketId() || '';
}

/**
 * Leave a specific channel.
 */
function leaveChannel(channelName: string): void {
  if (echoInstance && activeChannels.has(channelName)) {
    try {
      echoInstance.leave(channelName);
      activeChannels.delete(channelName);
      initializedChannels.delete(channelName);
    } catch (error) {
      console.error(`Failed to leave channel ${channelName}:`, error);
    }
  }
}

/**
 * Add a connection state callback.
 */
function onConnection(callback: () => void): () => void {
  connectionCallbacks.add(callback);
  return () => {
    connectionCallbacks.delete(callback);
  };
}

/**
 * Initialize a channel with ALL event listeners at once.
 */
async function initializeChannel(channelName: string): Promise<void> {
  if (!echoInstance) {
    await connect();
  }

  if (initializedChannels.has(channelName)) {
    return;
  }

  const channel = echoInstance!.private(channelName);

  channel
    .listen('.message.sent', (data: unknown) => {
      const msgData = data as { message: MessageEvent };
      messageCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(msgData.message);
        }
      });
    })
    .listen('.message.reaction', (data: unknown) => {
      reactionCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(data);
        }
      });
    })
    .listen('.message.read', (data: unknown) => {
      const readData = data as ReadReceiptEvent;
      readReceiptCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(readData);
        }
      });
    })
    .listen('.message.edited', (data: unknown) => {
      const editData = data as { message: MessageEvent };
      messageEditedCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(editData.message);
        }
      });
    })
    .listen('.message.deleted', (data: unknown) => {
      const deleteData = data as { message_id: string };
      messageDeletedCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(deleteData.message_id);
        }
      });
    })
    .listen('.poll.updated', (data: unknown) => {
      const pollData = data as { poll_id: string; votes: unknown[] };
      pollUpdateCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(pollData.poll_id, pollData.votes);
        }
      });
    })
    .listenForWhisper('typing', ((data: unknown) => {
      const typingData = data as TypingEvent;
      typingCallbacks.forEach(listener => {
        if (typeof listener === 'function') {
          listener(typingData);
        }
      });
    }) as (data: unknown) => void);

  activeChannels.add(channelName);
  initializedChannels.add(channelName);
}

/**
 * Listen for new messages in a chat channel.
 */
function listenForNewMessages(chatId: string, callback: (message: MessageEvent) => void): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `${chatId}_${Date.now()}`;

  messageCallbacks.set(listenerId, callback);
  initializeChannel(channelName);

  return () => {
    messageCallbacks.delete(listenerId);
    if (messageCallbacks.size === 0 && reactionCallbacks.size === 0 && typingCallbacks.size === 0) {
      leaveChannel(channelName);
      initializedChannels.delete(channelName);
    }
  };
}

/**
 * Listen for typing indicators in a chat.
 */
function listenForTyping(chatId: string, callback: (userId: string, isTyping: boolean) => void): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `typing_${chatId}_${Date.now()}`;

  const wrappedCallback = (data: TypingEvent) => {
    callback(data.user_id, data.is_typing);
  };

  typingCallbacks.set(listenerId, wrappedCallback);
  initializeChannel(channelName);

  return () => {
    typingCallbacks.delete(listenerId);
  };
}

/**
 * Listen for message read receipts.
 */
function listenForReadReceipts(chatId: string, callback: (messageId: string, userId: string) => void): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `read_${chatId}_${Date.now()}`;

  const wrappedCallback = (data: ReadReceiptEvent) => {
    callback(data.message_id, data.user_id);
  };

  readReceiptCallbacks.set(listenerId, wrappedCallback);
  initializeChannel(channelName);

  return () => {
    readReceiptCallbacks.delete(listenerId);
  };
}

/**
 * Listen for message reaction updates.
 */
function listenForReactionUpdates(chatId: string, callback: (event: unknown) => void): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `reaction_${chatId}_${Date.now()}`;

  reactionCallbacks.set(listenerId, callback);
  initializeChannel(channelName);

  return () => {
    reactionCallbacks.delete(listenerId);
  };
}

/**
 * Listen for message edits.
 */
function listenForMessageEdited(chatId: string, callback: MessageEditedCallback): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `edited_${chatId}_${Date.now()}`;

  messageEditedCallbacks.set(listenerId, callback);
  initializeChannel(channelName);

  return () => {
    messageEditedCallbacks.delete(listenerId);
  };
}

/**
 * Listen for message deletions.
 */
function listenForMessageDeleted(chatId: string, callback: MessageDeletedCallback): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `deleted_${chatId}_${Date.now()}`;

  messageDeletedCallbacks.set(listenerId, callback);
  initializeChannel(channelName);

  return () => {
    messageDeletedCallbacks.delete(listenerId);
  };
}

/**
 * Listen for poll updates.
 */
function listenForPollUpdates(chatId: string, callback: PollUpdateCallback): () => void {
  if (!chatId) return () => {};

  const channelName = `chat.${chatId}`;
  const listenerId = `poll_${chatId}_${Date.now()}`;

  pollUpdateCallbacks.set(listenerId, callback);
  initializeChannel(channelName);

  return () => {
    pollUpdateCallbacks.delete(listenerId);
  };
}

/**
 * Send typing indicator to a chat.
 */
async function notifyTyping(chatId: string, isTyping: boolean): Promise<void> {
  if (!chatId) return;

  try {
    if (!echoInstance) {
      await connect();
    }

    const channelName = `chat.${chatId}`;
    const userId = localStorage.getItem('user_id') || localStorage.getItem('userId') || '';

    if (isConnected.value && echoInstance) {
      (echoInstance.private(channelName) as PrivateChannel).whisper('typing', {
        user_id: userId,
        is_typing: isTyping,
        timestamp: Date.now()
      } as unknown);
    }
  } catch (error) {
    console.error('Failed to send typing notification:', error);
  }
}

/**
 * Mark messages as read.
 */
async function markAsRead(chatId: string, messageIds: string[]): Promise<void> {
  if (!chatId || !messageIds.length) return;

  try {
    const token = localStorage.getItem('token');
    if (!token) return;

    const apiUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8001/api';
    await fetch(`${apiUrl}/chats/${chatId}/messages/read`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ message_ids: messageIds }),
    });
  } catch (error) {
    console.error('Failed to mark messages as read:', error);
    throw error;
  }
}

/**
 * Export the composable for Vue components.
 */
export function useWebSocket() {
  onUnmounted(() => {
    // Don't disconnect on unmount - other components may be using it
  });

  return {
    isConnected,
    socketId,
    connect,
    disconnect,
    getSocketId,
    onConnection,
    leaveChannel,
    listenForNewMessages,
    listenForTyping,
    notifyTyping,
    listenForReadReceipts,
    markAsRead,
    listenForReactionUpdates,
    listenForMessageEdited,
    listenForMessageDeleted,
    listenForPollUpdates,
  };
}

export default useWebSocket;