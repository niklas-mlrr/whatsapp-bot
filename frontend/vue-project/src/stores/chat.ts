import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import apiClient from '@/services/api';

// Import types from chat.service.ts
type Message = {
  id: string;
  chat_id: string;
  sender_id: string;
  sender_phone?: string;
  recipient_phone?: string;
  content: string;
  type: string;
  status: string;
  created_at: string;
  updated_at: string;
  read_by?: string[];
  isSending?: boolean;
  temp_id?: string;
  isMe?: boolean;
  isFailed?: boolean;
  sender?: string;
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
  participants: any[];
  admin_ids?: string[];
  metadata?: Record<string, any>;
  avatar_url?: string;
  description?: string;
  isTyping?: boolean;
  isSelected?: boolean;
  isOnline?: boolean;
  lastSeen?: string;
};

export const useChatStore = defineStore('chat', () => {
  // State
  const chats = ref<Chat[]>([]);
  const currentChatId = ref<string | null>(null);
  const messages = ref<{ [key: string]: Message[] }>({});
  const loading = ref(false);
  const error = ref<string | null>(null);
  const searchQuery = ref('');
  const selectedMessages = ref<string[]>([]);
  const replyMessage = ref<Message | null>(null);
  const isTyping = ref(false);
  const typingUsers = ref<{ [key: string]: boolean }>({});

  // Getters
  const currentChat = computed<Chat | undefined>(() => {
    return chats.value.find(chat => chat.id === currentChatId.value);
  });

  const currentMessages = computed<Message[]>(() => {
    if (!currentChatId.value) return [];
    return messages.value[currentChatId.value] || [];
  });

  const unreadCount = computed<number>(() => {
    return chats.value.reduce((total, chat) => total + (chat.unread_count || 0), 0);
  });

  const filteredChats = computed<Chat[]>(() => {
    if (!searchQuery.value) return chats.value;
    
    const query = searchQuery.value.toLowerCase();
    return chats.value.filter(chat => 
      chat.name.toLowerCase().includes(query) ||
      chat.last_message?.toLowerCase().includes(query) ||
      chat.participants.some(p => p.name.toLowerCase().includes(query))
    );
  });

  // Actions
  const fetchChats = async () => {
    try {
      loading.value = true;
      const response = await apiClient.get('/chats');
      chats.value = response.data.data || [];
      error.value = null;
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to fetch chats';
      console.error('Error fetching chats:', err);
    } finally {
      loading.value = false;
    }
  };

  const fetchMessages = async (chatId: string) => {
    if (!chatId) return;
    
    try {
      loading.value = true;
      const response = await apiClient.get(`/chats/${chatId}/messages`);
      messages.value[chatId] = response.data.data || [];
      error.value = null;
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to fetch messages';
      console.error('Error fetching messages:', err);
    } finally {
      loading.value = false;
    }
  };

  const sendMessage = async (chatId: string, content: string, options: any = {}) => {
    try {
      const tempId = `temp_${Date.now()}`;
      const tempMessage: Message = {
        id: tempId,
        chat_id: chatId,
        sender_id: options.senderId || 'current-user',
        content,
        type: options.type || 'text',
        status: 'sending',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        isSending: true,
        temp_id: tempId,
        isMe: true,
        sender: 'You'
      };

      // Add temp message immediately
      if (!messages.value[chatId]) {
        messages.value[chatId] = [];
      }
      messages.value[chatId] = [...messages.value[chatId], tempMessage];

      // Send to server
      const response = await apiClient.post(`/chats/${chatId}/messages`, {
        content,
        type: options.type || 'text',
        ...options
      });

      // Replace temp message with server response
      const index = messages.value[chatId].findIndex(m => m.temp_id === tempId);
      if (index !== -1) {
        messages.value[chatId][index] = {
          ...response.data.data,
          isMe: true,
          sender: 'You'
        };
        messages.value[chatId] = [...messages.value[chatId]]; // Trigger reactivity
      }

      // Update last message in chat
      updateLastMessage(chatId, response.data.data);
      
      return response.data.data;
    } catch (err: any) {
      console.error('Error sending message:', err);
      
      // Mark message as failed
      if (options.tempId) {
        const index = messages.value[chatId]?.findIndex(m => m.temp_id === options.tempId);
        if (index !== -1 && messages.value[chatId]) {
          messages.value[chatId][index].status = 'failed';
          messages.value[chatId][index].isFailed = true;
          messages.value[chatId] = [...messages.value[chatId]]; // Trigger reactivity
        }
      }
      
      throw err;
    }
  };

  const updateLastMessage = (chatId: string, message: Message) => {
    const chat = chats.value.find(c => c.id === chatId);
    if (chat) {
      chat.last_message = message.content;
      chat.last_message_at = message.created_at;
      
      // Move chat to top
      chats.value = [
        chat,
        ...chats.value.filter(c => c.id !== chatId)
      ];
    }
  };

  const markAsRead = async (chatId: string, messageIds: string[]) => {
    try {
      await apiClient.post(`/chats/${chatId}/read`, { message_ids: messageIds });
      
      // Update read status in state
      if (messages.value[chatId]) {
        messages.value[chatId] = messages.value[chatId].map(msg => 
          messageIds.includes(msg.id) 
            ? { ...msg, status: 'read', read_by: [...(msg.read_by || []), 'current-user'] }
            : msg
        );
      }
      
      // Update unread count
      const chat = chats.value.find(c => c.id === chatId);
      if (chat) {
        chat.unread_count = Math.max(0, chat.unread_count - messageIds.length);
      }
    } catch (err) {
      console.error('Error marking messages as read:', err);
    }
  };

  const setTyping = (chatId: string, userId: string, isTyping: boolean) => {
    if (typingUsers.value[`${chatId}_${userId}`] !== isTyping) {
      typingUsers.value[`${chatId}_${userId}`] = isTyping;
      typingUsers.value = { ...typingUsers.value }; // Trigger reactivity
    }
    
    // Update isTyping for the chat
    const chat = chats.value.find(c => c.id === chatId);
    if (chat) {
      chat.isTyping = isTyping;
    }
  };

  const clearChat = (chatId: string) => {
    if (messages.value[chatId]) {
      messages.value[chatId] = [];
      messages.value = { ...messages.value }; // Trigger reactivity
    }
  };

  const selectMessage = (messageId: string) => {
    const index = selectedMessages.value.indexOf(messageId);
    if (index === -1) {
      selectedMessages.value.push(messageId);
    } else {
      selectedMessages.value.splice(index, 1);
    }
  };

  const clearSelection = () => {
    selectedMessages.value = [];
  };

  const setReplyMessage = (message: Message | null) => {
    replyMessage.value = message;
  };

  return {
    // State
    chats,
    currentChatId,
    messages,
    loading,
    error,
    searchQuery,
    selectedMessages,
    replyMessage,
    isTyping,
    typingUsers,
    
    // Getters
    currentChat,
    currentMessages,
    unreadCount,
    filteredChats,
    
    // Actions
    fetchChats,
    fetchMessages,
    sendMessage,
    markAsRead,
    setTyping,
    clearChat,
    selectMessage,
    clearSelection,
    setReplyMessage,
    updateLastMessage
  };
});
