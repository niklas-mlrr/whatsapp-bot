<template>
  <div v-if="error" class="p-4 text-red-600">
    An error occurred while loading messages. Please refresh the page.
    <button @click="error = null" class="ml-2 text-blue-600 hover:underline">
      Dismiss
    </button>
  </div>
  
  <div class="flex flex-col h-full" v-else>
    <!-- Loading indicator -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
    </div>
    
    <!-- Messages container -->
    <div 
      v-else
      ref="scrollContainer" 
      class="flex-1 overflow-y-auto p-4 space-y-4"
      @scroll="handleScroll"
    >
      <!-- Load more messages button -->
      <div v-if="hasMoreMessages && !loading" class="flex justify-center">
        <button 
          @click="loadMoreMessages"
          class="px-4 py-2 text-sm text-blue-600 hover:text-blue-800"
          :disabled="isLoadingMore"
        >
          {{ isLoadingMore ? 'Loading...' : 'Load older messages' }}
        </button>
      </div>

      <!-- Messages list -->
      <template v-if="Array.isArray(sortedMessages) && sortedMessages.length > 0">
        <div 
          v-for="message in sortedMessages" 
          :key="message?.id || message?.temp_id || Math.random()"
          class="message-item"
          :class="{
            'justify-end': message && isCurrentUser(message),
            'justify-start': !message || !isCurrentUser(message)
          }"
        >
          <div 
            v-if="message"
            class="message-bubble"
            :class="{
              'bg-blue-500 text-white': isCurrentUser(message),
              'bg-gray-100': !isCurrentUser(message)
            }"
          >
            <!-- Sender name for group chats -->
            <div 
              v-if="isGroupChat && message && !isCurrentUser(message)" 
              class="text-xs font-medium mb-1"
              :class="{
                'text-blue-100': isCurrentUser(message),
                'text-gray-600': !isCurrentUser(message)
              }"
            >
              {{ getSenderName(message) }}
            </div>
            
            <!-- Message content -->
            <div class="message-content">
              {{ message.content }}
            </div>
            
            <!-- Message metadata -->
            <div 
              class="message-meta flex items-center justify-end space-x-1 mt-1 text-xs"
              :class="{
                'text-blue-100': isCurrentUser(message),
                'text-gray-500': !isCurrentUser(message)
              }"
            >
              <span class="time">{{ formatTime(message.created_at) }}</span>
              <span v-if="isCurrentUser(message)" class="status">
                <template v-if="message.status === 'sending'">
                  <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                </template>
                <template v-else-if="message.status === 'sent' || message.status === 'delivered'">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                </template>
                <template v-else-if="message.status === 'read'">
                  <svg class="w-3 h-3 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L9 12.586l7.293-7.293a1 1 0 011.414 1.414l-8 8z" />
                  </svg>
                </template>
                <template v-else-if="message.status === 'failed'">
                  <svg class="w-3 h-3 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                  </svg>
                </template>
              </span>
            </div>
          </div>
        </div>
      </template>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex items-center space-x-2 p-2">
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
        <span class="text-sm text-gray-500">typing...</span>
      </div>
      
      <!-- New message indicator -->
      <div v-if="hasNewMessages" class="new-messages-indicator">
        <button @click="scrollToBottom({ behavior: 'smooth' })">
          New messages
        </button>
      </div>
      
      <!-- Scroll to bottom button -->
      <button
        v-if="!isScrolledToBottom"
        @click="scrollToBottom({ behavior: 'smooth' })"
        class="fixed bottom-24 right-6 bg-blue-500 text-white rounded-full p-3 shadow-lg hover:bg-blue-600 transition-colors"
      >
        ↓
      </button>
    </div>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex items-center space-x-2 p-2">
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
        <span class="text-sm text-gray-500">typing...</span>
      </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick, computed, watch } from 'vue';
import apiClient from '@/services/api';
import { useWebSocket } from '@/services/websocket';

// Types
interface Message {
  id: string;
  content: string;
  sender_id: string;
  sender?: string | { id: string; name: string };
  chat_id: string;
  chat?: string | { id: string; name: string };
  created_at: string;
  updated_at: string;
  read_by?: string[];
  temp_id?: string;
  status?: 'sending' | 'sent' | 'delivered' | 'read' | 'failed' | string;
  type?: 'text' | 'image' | 'video' | 'audio' | 'document' | 'location' | 'contact' | 'sticker' | 'unsupported' | string;
  direction?: 'incoming' | 'outgoing' | string;
  media?: string | null;
  mimetype?: string | null;
  reactions?: Record<string, any> | string | null;
  metadata?: Record<string, any> | string | null;
  sending_time?: string;
  is_group?: boolean | string | number;
  description?: string;
  avatar_url?: string | null;
}

interface TypingEvent {
  chat_id: string;
  user_id: string;
  is_typing: boolean;
  // For backward compatibility, include the old 'typing' property
  typing?: boolean;
}

interface ReadReceiptEvent {
  message_id: string;
  chat_id: string;
  user_id: string;
  read_at: string;
}

interface ChatMember {
  id: string;
  name: string;
}

const props = defineProps({
  chat: {
    type: [String, Number],
    required: true
  },
  isGroupChat: {
    type: Boolean,
    default: false
  },
  currentUser: {
    type: Object as () => { id: string; name: string },
    required: true
  },
  members: {
    type: Array as () => ChatMember[],
    default: () => []
  }
});

const emit = defineEmits(['load-more', 'message-read', 'typing']);

// State
const messages = ref<Message[]>([]);
const loading = ref(true);
const isLoadingMore = ref(false);
const hasMoreMessages = ref(true);
const lastMessageId = ref<string | null>(null);
const scrollContainer = ref<HTMLElement | null>(null);
const isScrolledToBottom = ref(true);
const typingUsers = ref<Record<string, boolean | undefined>>({});
const typingTimeouts = ref<Record<string, number>>({});
const isConnected = ref(false);
const reconnectAttempts = ref(0);
const maxReconnectAttempts = 5;
const reconnectTimeout = ref<number | null>(null);
const pollInterval = ref<number | null>(null);
const hasNewMessages = ref(false);
const isTyping = ref(false);
const error = ref<Error | null>(null);

// WebSocket composable
const { 
  connect: connectWebSocket, 
  disconnect: disconnectWebSocket, 
  listenForNewMessages, 
  listenForTyping, 
  listenForReadReceipts,
  notifyTyping,
  markAsRead
} = useWebSocket();

// Computed
const sortedMessages = computed(() => {
  try {
    if (!messages.value) {
      console.log('messages.value is undefined');
      return [];
    }
    
    if (!Array.isArray(messages.value)) {
      console.error('messages.value is not an array:', messages.value);
      error.value = new Error('Invalid messages format');
      return [];
    }
    
    // Ensure all messages have required fields
    const validMessages = messages.value.filter(msg => {
      try {
        if (!msg) {
          console.warn('Null or undefined message found in messages array');
          return false;
        }
        
        const hasId = Boolean(msg.id || msg.temp_id);
        const hasContent = Boolean(msg.content || msg.media);
        const hasSender = Boolean(msg.sender_id || msg.sender);
        
        const isValid = hasId && hasContent && hasSender;
        
        if (!isValid) {
          console.warn('Invalid message found:', {
            message: msg,
            hasId,
            hasContent,
            hasSender
          });
        }
        
        return isValid;
      } catch (err) {
        console.error('Error validating message:', err, 'Message:', msg);
        return false;
      }
    });
    
    try {
      return [...validMessages].sort((a, b) => {
        try {
          // Handle temporary messages (sending in progress)
          if (a.temp_id && !b.temp_id) return -1;
          if (!a.temp_id && b.temp_id) return 1;
          
          const dateA = a.created_at ? new Date(a.created_at).getTime() : 0;
          const dateB = b.created_at ? new Date(b.created_at).getTime() : 0;
          return dateA - dateB; // Oldest first (newest at bottom)
        } catch (sortError) {
          console.error('Error sorting messages:', sortError, 'a:', a, 'b:', b);
          return 0;
        }
      });
    } catch (sortError) {
      console.error('Error during message sorting:', sortError);
      return validMessages; // Return unsorted but valid messages
    }
  } catch (err: unknown) {
    const errorMessage = 'Error in sortedMessages computed property';
    console.error(errorMessage, err);
    error.value = err instanceof Error ? err : new Error(errorMessage);
    return [];
  }
});

// Helper functions
const isCurrentUser = (message: Message): boolean => {
  return message.sender_id === props.currentUser.id;
};

const getSenderName = (message: Message): string => {
  if (!props.isGroupChat || isCurrentUser(message)) return '';
  const sender = props.members.find(m => m.id === message.sender_id);
  return sender?.name || 'Unknown';
};

const formatTime = (dateString: string): string => {
  const date = new Date(dateString);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

// WebSocket event handlers
const handleNewMessage = (message: Message) => {
  // Prevent duplicates
  if (!messages.value.some(m => m.id === message.id || m.temp_id === message.temp_id)) {
    messages.value.push(message);
    lastMessageId.value = message.id;
    
    // Auto-scroll if at bottom
    if (isScrolledToBottom.value) {
      nextTick(() => scrollToBottom({ behavior: 'smooth' }));
    } else {
      hasNewMessages.value = true;
    }
  }
};

const handleTyping = (event: TypingEvent) => {
  if (event.user_id !== props.currentUser.id) {
    // Use is_typing if available, otherwise fall back to typing
    const isTyping = event.is_typing ?? event.typing ?? false;
    
    typingUsers.value = {
      ...typingUsers.value,
      [event.user_id]: isTyping
    };
    
    // Clear typing indicator after 3 seconds
    if (typingTimeouts.value[event.user_id]) {
      clearTimeout(typingTimeouts.value[event.user_id]);
    }
    
    if (isTyping) {
      typingTimeouts.value[event.user_id] = window.setTimeout(() => {
        typingUsers.value = {
          ...typingUsers.value,
          [event.user_id]: false
        };
      }, 3000);
    }
  }
};

const handleReadReceipt = (event: ReadReceiptEvent) => {
  messages.value = messages.value.map(msg => {
    if (msg.id === event.message_id && msg.status !== 'read') {
      return { ...msg, status: 'read' as const };
    }
    return msg;
  });
};

// Initialize WebSocket connection
const initWebSocket = async () => {
  console.group('initWebSocket');
  console.log('Starting WebSocket initialization...');
  console.log('Current chat ID:', props.chat);
  console.log('Current user ID:', props.currentUser?.id);
  
  try {
    console.log('Attempting to connect to WebSocket...');
    const connected = await connectWebSocket();
    console.log('connectWebSocket() result:', connected);
    
    if (connected) {
      console.log('WebSocket connected successfully');
      isConnected.value = true;
      reconnectAttempts.value = 0;
      
      console.log('Setting up WebSocket event listeners...');
      setupWebSocketListeners();
      
      console.log('Fetching latest messages...');
      await fetchLatestMessages();
      
      console.log('Marking visible messages as read...');
      markVisibleMessagesAsRead();
      
      console.log('WebSocket initialization completed successfully');
      console.groupEnd();
      return true;
    } else {
      console.error('Failed to establish WebSocket connection');
      console.groupEnd();
      throw new Error('Failed to connect to WebSocket');
    }
  } catch (error) {
    const errorObj = error as Error;
    console.error('WebSocket initialization failed:', errorObj);
    console.log('Error details:', {
      name: errorObj.name,
      message: errorObj.message,
      stack: errorObj.stack
    });
    isConnected.value = false;
    console.groupEnd();
    handleReconnect();
    return false;
  }
};

// Handle reconnection
const handleReconnect = () => {
  if (reconnectAttempts.value < maxReconnectAttempts) {
    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts.value), 30000);
    reconnectAttempts.value++;
    
    reconnectTimeout.value = window.setTimeout(() => {
      initWebSocket();
    }, delay);
  }
};

// Load more messages
const loadMoreMessages = async () => {
  if (isLoadingMore.value || !hasMoreMessages.value) return;
  
  isLoadingMore.value = true;
  
  try {
    const response = await apiClient.get(`/chats/${props.chat}/messages`, {
      params: {
        before: lastMessageId.value,
        limit: 20
      }
    });
    
    const newMessages = response.data.data || [];
    
    if (newMessages.length > 0) {
      // Add new messages to the beginning of the array
      messages.value = [...newMessages, ...messages.value];
      lastMessageId.value = newMessages[0].id;
      
      // Check if there are more messages to load
      hasMoreMessages.value = newMessages.length === 20;
      
      // Wait for DOM to update with new messages
      await nextTick();
      
      // Maintain scroll position
      if (scrollContainer.value) {
        const firstMessageElement = scrollContainer.value.querySelector('.message-item');
        if (firstMessageElement) {
          firstMessageElement.scrollIntoView(true);
        }
      }
    } else {
      hasMoreMessages.value = false;
    }
  } catch (error) {
    console.error('Error loading more messages:', error);
  } finally {
    isLoadingMore.value = false;
  }
};

// Handle scroll events
const handleScroll = () => {
  if (!scrollContainer.value) return;
  
  const { scrollTop, scrollHeight, clientHeight } = scrollContainer.value;
  const isAtBottom = scrollHeight - (scrollTop + clientHeight) < 50;
  
  isScrolledToBottom.value = isAtBottom;
  
  // If scrolled to bottom and there were new messages, mark as read
  if (isAtBottom && hasNewMessages.value) {
    hasNewMessages.value = false;
    markVisibleMessagesAsRead();
  }
  
  // Load more messages when scrolling near the top
  if (scrollTop < 100 && hasMoreMessages.value && !isLoadingMore.value) {
    loadMoreMessages();
  }
};

// Scroll to bottom of the container
const scrollToBottom = (options?: ScrollToOptions) => {
  if (scrollContainer.value) {
    scrollContainer.value.scrollTo({
      top: scrollContainer.value.scrollHeight,
      ...options
    });
    hasNewMessages.value = false;
  }
};

// Mark visible messages as read
const markVisibleMessagesAsRead = () => {
  const unreadMessages = messages.value.filter(
    msg => !msg.read_by?.includes(props.currentUser.id) && 
           msg.sender_id !== props.currentUser.id
  );
  
  if (unreadMessages.length > 0) {
    const messageIds = unreadMessages.map(msg => msg.id).filter(Boolean) as string[];
    markMessagesAsRead(messageIds);
  }
};

// Start polling for new messages (fallback if WebSocket fails)
const startPolling = () => {
  if (pollInterval.value) {
    clearInterval(pollInterval.value);
  }
  
  // Initial fetch
  fetchLatestMessages();
  
  // Set up polling interval
  pollInterval.value = window.setInterval(() => {
    if (document.visibilityState === 'visible') {
      fetchLatestMessages();
    }
  }, 5000); // Poll every 5 seconds
};

// Process and normalize message data to ensure required fields exist
const normalizeMessage = (msg: any): Message => {
  return {
    id: msg.id?.toString() || '',
    content: msg.content || '',
    sender_id: msg.sender_id || msg.sender || 'unknown',
    chat_id: msg.chat_id || msg.chat || 'unknown',
    type: msg.type || 'text',
    direction: msg.direction || 'incoming',
    status: msg.status || 'sent',
    created_at: msg.created_at || new Date().toISOString(),
    updated_at: msg.updated_at || new Date().toISOString(),
    read_by: Array.isArray(msg.read_by) ? msg.read_by : [],
    media: msg.media || null,
    mimetype: msg.mimetype || null,
    reactions: msg.reactions || {},
    metadata: msg.metadata || {},
    // Add any other fields with default values as needed
  };
};

// Fetch latest messages with deduplication and proper ordering
const fetchLatestMessages = async () => {
  console.log('fetchLatestMessages called with chat:', props.chat);
  if (!props.chat) {
    console.log('No chat ID provided, skipping fetch');
    loading.value = false;
    return;
  }
  
  try {
    loading.value = true;
    console.log('Fetching latest messages for chat:', props.chat);
    console.log('Current messages length:', messages.value?.length);
    console.log('Last message ID:', messages.value?.[messages.value?.length - 1]?.id);
    
    const response = await apiClient.get(`/chats/${props.chat}/messages/latest`, {
      params: {
        after: messages.value?.[messages.value?.length - 1]?.id || null
      }
    });
    
    console.log('API response:', response);
    
    // Process and normalize the messages
    const newMessages = Array.isArray(response?.data?.data) 
      ? response.data.data.map(normalizeMessage) 
      : [];
    
    if (newMessages.length > 0) {
      // Store the current scroll position
      const container = scrollContainer.value;
      const wasScrolledToBottom = container 
        ? container.scrollHeight - container.scrollTop - container.clientHeight < 50
        : false;
      
      // Create a map of existing message IDs for quick lookup
      const existingMessageIds = new Set(messages.value.map(m => m.id));
      
      // Filter out any messages we already have and ensure they're valid
      const uniqueNewMessages = newMessages.filter((msg: Message) => 
        msg?.id && !existingMessageIds.has(msg.id)
      );
      
      if (uniqueNewMessages.length > 0) {
        // Add new messages to the end of the array and sort by created_at
        messages.value = [...messages.value, ...uniqueNewMessages].sort((a, b) => {
          const dateA = a.created_at ? new Date(a.created_at).getTime() : 0;
          const dateB = b.created_at ? new Date(b.created_at).getTime() : 0;
          return dateA - dateB; // Oldest first (newest at bottom)
        });
        
        // If user was scrolled to bottom or it's a new message, scroll to bottom
        nextTick(() => {
          if (wasScrolledToBottom || isScrolledToBottom.value) {
            scrollToBottom({ behavior: 'smooth' });
          } else if (!wasScrolledToBottom) {
            hasNewMessages.value = true;
          }
        });
        
        // Mark messages as read if they're not from the current user
        const currentUserId = props.currentUser?.id;
        if (currentUserId) {
          const unreadMessages = uniqueNewMessages.filter(
            (msg: Message) => 
              // Only mark as unread if it's not from the current user
              // and doesn't have the current user in read_by array
              msg.sender_id !== currentUserId.toString() &&
              !(Array.isArray(msg.read_by) && msg.read_by.includes(currentUserId))
          );
          
          if (unreadMessages.length > 0) {
            const messageIds = unreadMessages
              .map((msg: Message) => msg?.id)
              .filter(Boolean) as string[];
              
            if (messageIds.length > 0) {
              markMessagesAsRead(messageIds);
            }
          }
        }
      }
    }
  } catch (error) {
    console.error('Error fetching latest messages:', error);
    // If there's an error, try again after a delay
    if (pollInterval.value) {
      clearInterval(pollInterval.value);
    }
    pollInterval.value = window.setTimeout(fetchLatestMessages, 10000); // Retry after 10 seconds
  } finally {
    loading.value = false;
  }
};

// Mark messages as read
const markMessagesAsRead = async (messageIds: string[]) => {
  if (messageIds.length === 0) return;
  
  try {
    await apiClient.post('/messages/read', {
      message_ids: messageIds
    });
    
    // Update local message status
    messages.value.forEach(message => {
      if (messageIds.includes(message.id) && message.status !== 'read') {
        message.status = 'read';
        message.read_by = message.read_by || [];
        if (!message.read_by.includes(props.currentUser.id)) {
          message.read_by.push(props.currentUser.id);
        }
      }
    });
    
    emit('message-read', messageIds);
  } catch (error) {
    console.error('Error marking messages as read:', error);
  }
};

// Lifecycle hooks
function handleVisibilityChange() {
  if (document.visibilityState === 'visible') {
    markVisibleMessagesAsRead();
  }
}

function handleWindowFocus() {
  markVisibleMessagesAsRead();
}

onMounted(async () => {
  console.group('MessageList Component Lifecycle');
  console.log('1. Component mounted');
  console.log('2. Props:', {
    chat: props.chat,
    isGroupChat: props.isGroupChat,
    currentUser: props.currentUser?.id || 'Not available'
  });
  console.log('3. Initial messages state:', messages.value);
  
  try {
    console.log('Setting up window event listeners...');
    window.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', handleWindowFocus);
    
    console.log('Initializing WebSocket connection...');
    const webSocketInitialized = await initWebSocket();
    
    // If WebSocket initialization failed, fall back to polling
    if (!webSocketInitialized) {
      console.warn('WebSocket initialization failed, falling back to polling');
      loading.value = true;
      console.log('Fetching latest messages via polling...');
      await fetchLatestMessages();
      console.log('Starting polling interval...');
      startPolling();
    } else {
      console.log('WebSocket initialized successfully');
    }
    
    loading.value = false;
    console.log('Component initialization completed');
  } catch (error) {
    const errorObj = error as Error;
    console.error('Error initializing MessageList:', errorObj);
    console.log('Error details:', {
      name: errorObj.name,
      message: errorObj.message,
      stack: errorObj.stack
    });
    
    loading.value = false;
    
    // Start polling as fallback
    console.log('Starting fallback polling due to initialization error...');
    try {
      await fetchLatestMessages();
      startPolling();
    } catch (fetchError) {
      const fetchErrorObj = fetchError as Error;
      console.error('Error in fallback polling:', fetchErrorObj);
    }
  } finally {
    console.groupEnd();
  }
});

// Clean up on unmount
onUnmounted(() => {
  console.group('MessageList Component Unmounting');
  console.log('1. Component unmounting - starting cleanup');
  
  // Clean up any remaining timeouts or intervals
  console.log('2. Cleaning up typing timeouts');
  Object.entries(typingTimeouts.value).forEach(([userId, timeoutId]) => {
    console.log(`   - Clearing timeout for user ${userId}`);
    clearTimeout(timeoutId);
  });
  
  if (pollInterval.value) {
    console.log('3. Clearing poll interval');
    clearInterval(pollInterval.value);
  }
  
  if (reconnectTimeout.value) {
    console.log('4. Clearing reconnect timeout');
    clearTimeout(reconnectTimeout.value);
  }
  
  // Disconnect WebSocket
  disconnectWebSocket();
  
  // Remove event listeners
  window.removeEventListener('visibilitychange', handleVisibilityChange);
  window.removeEventListener('focus', handleWindowFocus);
});

// Handle WebSocket disconnection and reconnection
const handleDisconnect = () => {
  isConnected.value = false;
  
  if (reconnectAttempts.value < maxReconnectAttempts) {
    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts.value), 30000); // Exponential backoff with max 30s
    reconnectAttempts.value++;
    
    reconnectTimeout.value = window.setTimeout(() => {
      console.log(`Attempting to reconnect (${reconnectAttempts.value}/${maxReconnectAttempts})...`);
      initWebSocket();
    }, delay);
  } else {
    console.error('Max reconnection attempts reached');
    // Notify user about connection issues
  }
};

// Set up WebSocket event listeners
const setupWebSocketListeners = () => {
  console.group('setupWebSocketListeners');
  console.log('1. Starting WebSocket listener setup');
  
  if (!props.chat) {
    const errorMsg = 'Cannot set up WebSocket listeners: No chat ID provided';
    console.error(errorMsg);
    console.groupEnd();
    throw new Error(errorMsg);
  }
  
  console.log('2. Chat ID:', props.chat);
  console.log('3. Current user ID:', props.currentUser?.id || 'Not available');
  console.log('4. Current messages value:', messages.value);
  
  try {
    // Listen for new messages
    console.log('5. Setting up new message listener');
    const newMessageUnsubscribe = listenForNewMessages(props.chat, (message: any) => {
      console.group('New WebSocket Message');
      console.log('5.1 Raw message received:', message);
      console.log('5.2 Current messages value before processing:', messages.value);
      
      if (!message) {
        console.error('Received empty message from WebSocket');
        console.groupEnd();
        return;
      }
      
      // Initialize messages array if it's undefined or not an array
      if (!Array.isArray(messages.value)) {
        console.log('Initializing messages array');
        messages.value = [];
      }
      
      // Ensure messages.value is an array before accessing length
      const currentMessages = Array.isArray(messages.value) ? messages.value : [];
      console.log('Processing new message. Current messages count:', currentMessages.length);
      
      try {
        // Ensure messages.value is an array before using some()
        const currentMessages = Array.isArray(messages.value) ? messages.value : [];
        
        const messageExists = currentMessages.some(m => 
          (m && m.id && message && m.id === message.id) || 
          (m && m.temp_id && message && m.temp_id === message.temp_id)
        );
        
        if (!messageExists && message) {
          const normalizedMessage = normalizeMessage(message);
          messages.value = [...currentMessages, normalizedMessage];
          
          // Auto-scroll if user is at bottom
          if (isScrolledToBottom.value) {
            nextTick(() => {
              scrollToBottom({ behavior: 'smooth' });
            });
          }
          
          // Mark as read if it's not from the current user
          if (normalizedMessage.sender_id !== props.currentUser?.id) {
            markMessagesAsRead([normalizedMessage.id || normalizedMessage.temp_id].filter(Boolean) as string[]);
          }
        }
      } catch (error) {
        const errorObj = error as Error;
        console.error('Error processing new message:', errorObj);
        console.log('Message that caused error:', JSON.parse(JSON.stringify(message)));
        console.log('Error details:', {
          name: errorObj.name,
          message: errorObj.message,
          stack: errorObj.stack
        });
      } finally {
        console.groupEnd();
      }
    });
    
    // Listen for typing indicators
    const typingUnsubscribe = listenForTyping(props.chat, (event: any) => {
      console.group('Typing Event');
      console.log('Raw typing event:', JSON.parse(JSON.stringify(event)));
      
      if (!event || !event.user_id) {
        console.error('Invalid typing event received:', event);
        console.groupEnd();
        return;
      }
      
      console.log(`User ${event.user_id} is ${event.typing ? 'typing' : 'not typing'}`);
      
      if (event.typing) {
        typingUsers.value[event.user_id] = true;
        
        // Clear previous timeout if exists
        if (typingTimeouts.value[event.user_id]) {
          clearTimeout(typingTimeouts.value[event.user_id]);
        }
        
        // Set timeout to remove typing indicator after 3 seconds
        typingTimeouts.value[event.user_id] = window.setTimeout(() => {
          delete typingUsers.value[event.user_id];
          typingUsers.value = { ...typingUsers.value };
        }, 3000);
      } else {
        delete typingUsers.value[event.user_id];
      }
      
      // Trigger reactivity
      typingUsers.value = { ...typingUsers.value };
      console.log('Updated typing users:', { ...typingUsers.value });
      console.groupEnd();
    });
    
    // Listen for read receipts
    const readReceiptUnsubscribe = listenForReadReceipts(props.chat, (event: any) => {
      console.group('Read Receipt');
      console.log('Raw read receipt:', JSON.parse(JSON.stringify(event)));
      
      if (!event || !event.message_id) {
        console.error('Invalid read receipt received:', event);
        console.groupEnd();
        return;
      }
      
      console.log(`Message ${event.message_id} marked as read by user ${event.user_id}`);
      
      messages.value = messages.value.map(msg => {
        if (msg.id === event.message_id) {
          return {
            ...msg,
            status: 'read' as const,
            read_by: [...(msg.read_by || []), event.user_id].filter((v, i, a) => a.indexOf(v) === i)
          };
        }
        return msg;
      });
      
      console.log('Updated messages with read receipt:', messages.value);
      console.groupEnd();
    });
    
    // Store unsubscribe functions
    const unsubscribeFunctions = {
      newMessage: newMessageUnsubscribe,
      typing: typingUnsubscribe,
      readReceipt: readReceiptUnsubscribe
    };
    
    console.log('6. WebSocket listeners setup completed successfully');
    console.groupEnd();
    
    // Clean up WebSocket listeners on unmount
    return () => {
      console.log('Cleaning up WebSocket listeners');
      Object.entries(unsubscribeFunctions).forEach(([name, unsubscribe]) => {
        console.log(`Unsubscribing from ${name} listener`);
        if (typeof unsubscribe === 'function') {
          try {
            unsubscribe();
            console.log(`Successfully unsubscribed from ${name}`);
          } catch (err) {
            console.error(`Error unsubscribing from ${name}:`, err);
          }
        } else {
          console.warn(`No unsubscribe function for ${name}`);
        }
      });
      console.log('WebSocket listeners cleanup completed');
    };
  } catch (error) {
    const errorObj = error as Error;
    console.error('Error setting up WebSocket listeners:', errorObj);
    console.log('Error details:', {
      name: errorObj.name,
      message: errorObj.message,
      stack: errorObj.stack
    });
    console.groupEnd();
    throw errorObj;
  } finally {
    console.groupEnd();
  }
};

// Fetch messages from API
const fetchMessages = async (params: { chatId: string; limit: number; before?: string }): Promise<{ messages: Message[]; hasMore: boolean }> => {
  try {
    console.log('Fetching messages with params:', params);
    const response = await apiClient.get('/messages', { params });
    
    // Ensure messages are properly typed and have required fields
    const messages = (response.data.data || []).map((msg: any) => ({
      id: msg.id || '',
      content: msg.content || '',
      sender_id: msg.sender_id || '',
      chat_id: msg.chat_id || params.chatId,
      created_at: msg.created_at || new Date().toISOString(),
      updated_at: msg.updated_at || new Date().toISOString(),
      status: msg.status || 'sent',
      read_by: Array.isArray(msg.read_by) ? msg.read_by : [],
      // Add other required fields with defaults
      ...msg
    }));
    
    console.log(`Fetched ${messages.length} messages`);
    
    return {
      messages,
      hasMore: response.data.meta?.has_more || false
    };
  } catch (error) {
    const errorObj = error as Error;
    console.error('Error fetching messages:', errorObj);
    throw errorObj;
  }
};

</script>

<style scoped>
/* Message container */
.message {
  margin-bottom: 1rem;
  transition: all 0.2s ease;
}

/* Typing indicator */
.typing-indicator {
  display: flex;
  align-items: center;
  padding: 0.5rem 1rem;
  margin: 0.25rem 0;
  border-radius: 1rem;
  background-color: #f3f4f6;
  width: fit-content;
  max-width: 80%;
}

.typing-dot {
  width: 0.5rem;
  height: 0.5rem;
  margin: 0 0.125rem;
  background-color: #9ca3af;
  border-radius: 50%;
  display: inline-block;
  animation: typingAnimation 1.4s infinite ease-in-out;
}

.typing-dot:nth-child(1) {
  animation-delay: 0s;
}

.typing-dot:nth-child(2) {
  animation-delay: 0.2s;
}

.typing-dot:nth-child(3) {
  animation-delay: 0.4s;
}

@keyframes typingAnimation {
  0%, 60%, 100% {
    transform: translateY(0);
    opacity: 0.6;
  }
  30% {
    transform: translateY(-0.25rem);
    opacity: 1;
  }
}

/* New messages indicator */
.new-messages-indicator {
  position: sticky;
  bottom: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 10;
  text-align: center;
  margin: 1rem 0;
}

.new-messages-indicator button {
  background-color: #3b82f6;
  color: white;
  border: none;
  border-radius: 1rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  transition: all 0.2s ease;
}

.new-messages-indicator button:hover {
  background-color: #2563eb;
  transform: translateY(-1px);
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

/* Scrollbar styling */
::-webkit-scrollbar {
  width: 6px;
}

::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 3px;
}

::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}

/* Message status indicators */
.message-status {
  display: inline-flex;
  align-items: center;
  margin-left: 0.25rem;
  font-size: 0.75rem;
  opacity: 0.8;
}

/* Responsive adjustments */
@media (max-width: 640px) {
  .message {
    margin-bottom: 0.75rem;
  }
  
  .typing-indicator {
    max-width: 90%;
  }
}

/* Animation for new messages */
@keyframes newMessage {
  from {
    opacity: 0;
    transform: translateY(0.5rem);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.message-enter-active {
  animation: newMessage 0.3s ease-out;
}
</style>

<style scoped>
.message-item {
  display: flex;
  margin-bottom: 0.5rem;
}

.message-bubble {
  border-radius: 0.5rem;
  padding-left: 1rem;
  padding-right: 1rem;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
  max-width: 20rem;
  word-break: break-word;
  box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
}
@media (min-width: 768px) {
  .message-bubble { max-width: 28rem; }
}
@media (min-width: 1024px) {
  .message-bubble { max-width: 32rem; }
}
@media (min-width: 1280px) {
  .message-bubble { max-width: 42rem; }
}

.message-content {
  font-size: 0.875rem;
}

.message-meta {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-top: 0.25rem;
  font-size: 0.75rem;
  gap: 0.25rem;
}

.time {
  opacity: 0.75;
}

.status {
  display: flex;
  align-items: center;
}
</style>
