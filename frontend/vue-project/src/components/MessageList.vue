<template>
  <div v-if="error" class="p-4 text-red-600">
    Beim Laden der Nachrichten ist ein Fehler aufgetreten. Bitte laden Sie die Seite neu.
    <button @click="error = null" class="ml-2 text-blue-600 hover:underline">
      Schließen
    </button>
  </div>
  
  <div class="flex flex-col h-full" v-else>
    <!-- Loading indicator -->
    <div v-if="loading" class="flex-1 flex items-center justify-center bg-white dark:bg-zinc-900">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
    </div>
    
    <!-- Messages container -->
    <div 
      v-else
      ref="scrollContainer" 
      class="flex-1 overflow-y-auto p-3 md:p-4 space-y-3 md:space-y-4 bg-white dark:bg-zinc-900"
      @scroll="handleScroll"
    >
      <!-- Load more messages button -->
      <div v-if="hasMoreMessages && !loading" class="flex justify-center">
        <button 
          @click="loadMoreMessages"
          class="px-4 py-2 text-sm text-blue-600 hover:text-blue-800"
          :disabled="isLoadingMore"
        >
          {{ isLoadingMore ? 'Lädt...' : 'Ältere Nachrichten laden' }}
        </button>
      </div>

      <!-- Messages list -->
      <template v-if="Array.isArray(sortedMessages) && sortedMessages.length > 0">
        <template 
          v-for="(message, index) in sortedMessages" 
          :key="message?.id ? `id-${message.id}` : (message?.temp_id ? `tmp-${message.temp_id}` : `idx-${index}`)"
        >
          <!-- Day separator -->
          <div v-if="needsDaySeparator(index)" class="flex items-center my-3 select-none">
            <div class="flex-1 h-px bg-gray-200"></div>
            <div class="mx-3 text-xs text-gray-500 bg-gray-100 border border-gray-200 rounded-full px-3 py-0.5">
              {{ formatDayLabel(message.created_at) }}
            </div>
            <div class="flex-1 h-px bg-gray-200"></div>
          </div>

          <!-- Unread messages indicator -->
          <div v-if="needsUnreadIndicator(index)" class="flex items-center my-4 select-none">
            <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
            <div class="mx-3 text-xs font-semibold text-gray-700 dark:text-gray-300 bg-gray-200 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-full px-4 py-1">
              Neue Nachrichten
            </div>
            <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
          </div>

          <!-- Message item -->
          <MessageItem 
            v-if="message"
            :message="{
              ...message,
              isMe: isMine(message),
              sender: isGroupChat 
                ? ((typeof message.sender_name === 'string' && message.sender_name && message.sender_name.trim().toLowerCase() !== 'whatsapp user')
                    ? message.sender_name
                    : ((typeof message.sender === 'string' && message.sender.trim() && message.sender.trim().toLowerCase() !== 'whatsapp user')
                        ? message.sender
                        : getSenderLabel(message)))
                : (typeof message.sender === 'string' ? message.sender : ''),
              sender_name: (isGroupChat 
                ? ((typeof message.sender_name === 'string' && message.sender_name && message.sender_name.trim().toLowerCase() !== 'whatsapp user')
                    ? message.sender_name
                    : ((typeof message.sender === 'string' && message.sender.trim() && message.sender.trim().toLowerCase() !== 'whatsapp user')
                        ? message.sender
                        : getSenderLabel(message)))
                : (typeof message.sender === 'string' ? message.sender : '')),
              sender_avatar_url: getSenderAvatar(message)
            }"
            :current-user="currentUser"
            :is-group-chat="isGroupChat"
            :members="members"
            @open-image-preview="handleOpenImagePreview"
            @add-reaction="handleAddReaction"
            @remove-reaction="handleRemoveReaction"
            @reply-to-message="handleReplyToMessage"
            @edit-message="handleEditMessage"
            @delete-message="handleDeleteMessage"
          />
        </template>
      </template>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex items-center space-x-2 p-2">
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
        <span class="text-sm text-gray-500">tippt...</span>
      </div>
      
      <!-- New message indicator -->
      <div v-if="hasNewMessages" class="new-messages-indicator">
        <button @click="scrollToBottom({ behavior: 'smooth' })">
          Neue Nachrichten
        </button>
      </div>
      
      <!-- Scroll to bottom button -->
      <button
        v-if="!isScrolledToBottom"
        @click="scrollToBottom({ behavior: 'smooth' })"
        class="fixed bottom-20 md:bottom-24 right-4 md:right-6 bg-blue-500 text-white rounded-full p-2 md:p-3 shadow-lg hover:bg-blue-600 transition-colors z-10"
      >
        ↓
      </button>
    </div>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex items-center space-x-2 p-2">
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
        <span class="text-sm text-gray-500">tippt...</span>
      </div>
    </div>
    
    <!-- Image Preview Modal -->
    <ImagePreviewModal
      :is-open="imagePreviewOpen"
      :image-src="previewImageSrc"
      :caption="previewImageCaption"
      :images="imageList"
      :current-index="currentImageIndex"
      @close="closeImagePreview"
      @update-index="updateImageIndex"
    />
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick, computed, watch } from 'vue';
import apiClient from '@/services/api';
import { useWebSocket } from '@/services/websocket';
import MessageItem from './MessageItem.vue';
import ImagePreviewModal from './ImagePreviewModal.vue';
import { useChatStore } from '@/stores/chat';

// Types
interface MediaObject {
  path?: string | null;
  url?: string | null;
  thumbnail_url?: string | null;
  metadata?: Record<string, any>;
  [key: string]: any;
}

interface Message {
  id: string;
  content: string;
  sender_id: string;
  sender_phone?: string;
  sender?: string | { id: string; name: string };
  sender_name?: string;
  chat_id: string;
  chat?: string | { id: string; name: string };
  created_at: string;
  updated_at: string;
  read_by?: string[];
  temp_id?: string;
  status?: 'sending' | 'sent' | 'delivered' | 'read' | 'failed' | string;
  type?: 'text' | 'image' | 'video' | 'audio' | 'document' | 'location' | 'contact' | 'sticker' | 'unsupported' | string;
  direction?: 'incoming' | 'outgoing' | string;
  media?: string | MediaObject | null;
  media_url?: string;
  mimetype?: string | null;
  filename?: string;
  size?: number;
  reactions?: Record<string, any> | string | null;
  reaction_users?: Record<string, string>;
  metadata?: Record<string, any> | string | null;
  sending_time?: string;
  is_group?: boolean | string | number;
  description?: string;
  avatar_url?: string | null;
  reply_to_message_id?: string | number;
  quoted_message?: any;
  reply_to_message?: any;
  is_from_me?: boolean;
  is_mine?: boolean;
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
  phone?: string;
  phone_number?: string;
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

const emit = defineEmits(['load-more', 'message-read', 'typing', 'reply-to-message', 'edit-message']);

// Pinia chat store for resolving contact names/avatars for group senders
const chatStore = useChatStore();
onMounted(() => {
  // Ensure chats are loaded once; ignore errors (UI has separate fetch paths)
  if (!Array.isArray((chatStore as any).chats) || (chatStore as any).chats.length === 0) {
    try { (chatStore as any).fetchChats?.(); } catch {}
  }
});

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
const isTyping = computed(() => {
  return Object.values(typingUsers.value).some(typing => typing === true);
});
const error = ref<Error | null>(null);
// Use a plain variable instead of ref to avoid triggering reactivity/re-renders
let isInitialLoad = true;
let initialLoadTimeoutSet = false; // Flag to ensure timeout is only set once
const lastReadMessageId = ref<string | null>(null);
const firstUnreadMessageId = ref<string | null>(null);

// Image preview state
const imagePreviewOpen = ref(false);
const previewImageSrc = ref('');
const previewImageCaption = ref('');
const currentImageIndex = ref(0);
const imageList = ref<string[]>([]);

// WebSocket composable
const { 
  connect: connectWebSocket, 
  disconnect: disconnectWebSocket, 
  listenForNewMessages, 
  listenForTyping, 
  listenForReadReceipts,
  listenForReactionUpdates,
  listenForMessageEdited,
  listenForMessageDeleted,
  notifyTyping,
  markAsRead
} = useWebSocket();

// Computed
const sortedMessages = computed(() => {
  try {
    if (!messages.value) {
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
          return false;
        }
        
        const hasId = Boolean(msg.id || msg.temp_id);
        const hasContentOrMedia = Boolean(msg.content || msg.media || msg.type === 'image' || msg.mimetype?.startsWith('image/'));
        const hasSender = Boolean(msg.sender_id || msg.sender);
        
        return hasId && hasContentOrMedia && hasSender;
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
  // 1) Trust backend boolean if provided
  if (typeof (message as any).is_from_me === 'boolean') {
    return (message as any).is_from_me === true;
  }
  // 2) Temp/UI messages may set sender to 'me'
  if ((message as any).sender === 'me') return true;
  // 3) Prefer explicit phone marker from backend
  if ((message as any).sender_phone === 'me') return true;
  // 4) Resolve via members: sender_id belongs to member with phone === 'me'
  if (Array.isArray(props.members) && props.members.length > 0) {
    const m = props.members.find(m => m.id?.toString?.() === message.sender_id?.toString?.());
    if (m && (m as any).phone === 'me') return true;
  }
  // 3) Fallback to id comparison
  return message.sender_id?.toString() === props.currentUser.id?.toString();
};

// Unified helper used in template
const isMine = (message: Message): boolean => {
  if (typeof (message as any).is_mine === 'boolean') return (message as any).is_mine;
  return isCurrentUser(message);
};

// Derive current user for this chat: always use authenticated user id
const currentUserForChat = computed(() => {
  return { id: props.currentUser.id, name: props.currentUser.name }
})

// Helper: normalize a phone-like value (JID or phone) to digits only for matching
const normalizePhone = (val?: string | null): string => {
  if (!val) return '';
  const withoutDomain = String(val).replace(/@.*$/, '');
  return withoutDomain.replace(/\D/g, '');
};

// Find a direct contact chat by JID/phone digits
const findDirectChatByAnyPhone = (candidates: string[]): any | undefined => {
  if (!Array.isArray(chatStore.chats) || chatStore.chats.length === 0) return undefined;
  const normSet = new Set(candidates.map(normalizePhone).filter(Boolean));
  if (normSet.size === 0) return undefined;
  return chatStore.chats.find((c: any) => {
    if (c?.is_group) return false;
    const parts: string[] = Array.isArray(c?.participants) ? c.participants : [];
    const meta = (c?.metadata && typeof c.metadata === 'object') ? c.metadata : {};
    const jid = typeof meta.whatsapp_id === 'string' ? meta.whatsapp_id : '';
    const phoneCandidates: string[] = [jid, ...parts];
    const found = phoneCandidates.some((p) => normSet.has(normalizePhone(p)));
    return found;
  });
};

// Find the member for a message using multiple strategies (id, phone, JID)
const findMemberForMessage = (message: Message): ChatMember | undefined => {
  const anyMsg = message as any;
  // 1) Try by id
  const sid = anyMsg.sender_id != null ? String(anyMsg.sender_id) : (anyMsg.senderId != null ? String(anyMsg.senderId) : null);
  if (sid) {
    const byId = props.members.find(m => m.id === sid);
    if (byId) return byId;
  }
  // 2) Try by phone candidates
  const meta = (anyMsg.metadata && typeof anyMsg.metadata === 'object') ? anyMsg.metadata : {};
  const phoneCandidates: string[] = [];
  if (typeof anyMsg.sender_phone === 'string') phoneCandidates.push(anyMsg.sender_phone);
  if (typeof meta.sender_phone === 'string') phoneCandidates.push(meta.sender_phone);
  if (typeof meta.remoteJid === 'string') phoneCandidates.push(meta.remoteJid);
  if (typeof anyMsg.senderJid === 'string') phoneCandidates.push(anyMsg.senderJid);
  if (typeof anyMsg.from === 'string') phoneCandidates.push(anyMsg.from);
  const normalizedCandidates = phoneCandidates
    .map(p => normalizePhone(p))
    .filter(p => p.length > 0);
  if (normalizedCandidates.length === 0) return undefined;
  // Compare with member phones
  for (const cand of normalizedCandidates) {
    const found = props.members.find((m: any) => {
      const mPhone = m?.phone || m?.phone_number;
      return normalizePhone(mPhone) === cand;
    });
    if (found) return found;
  }
  return undefined;
};

const getSenderName = (message: Message): string => {
  if (!props.isGroupChat || isCurrentUser(message)) return '';
  const sender = findMemberForMessage(message);
  if (sender?.name && String(sender.name).trim()) return String(sender.name);
  // Try to pull name from a known direct chat contact
  const anyMsg = message as any;
  const meta = (anyMsg.metadata && typeof anyMsg.metadata === 'object') ? anyMsg.metadata : {};
  const phoneCandidates: string[] = [];
  if (typeof anyMsg.sender_phone === 'string') phoneCandidates.push(anyMsg.sender_phone);
  if (typeof meta.sender_phone === 'string') phoneCandidates.push(meta.sender_phone);
  if (typeof meta.remoteJid === 'string') phoneCandidates.push(meta.remoteJid);
  if (typeof anyMsg.senderJid === 'string') phoneCandidates.push(anyMsg.senderJid);
  if (typeof anyMsg.from === 'string') phoneCandidates.push(anyMsg.from);
  const contactChat = findDirectChatByAnyPhone(phoneCandidates);
  if (contactChat?.name && String(contactChat.name).trim()) return String(contactChat.name);
  return '';
};

const getSenderPhone = (message: Message): string => {
  if (!props.isGroupChat || isCurrentUser(message)) return '';
  const sender = findMemberForMessage(message) as any;
  return sender?.phone || '';
};

const getSenderLabel = (message: Message): string => {
  if (!props.isGroupChat || isCurrentUser(message)) return '';
  const name = getSenderName(message);
  if (name && name.trim()) return name;
  const phone = getSenderPhone(message);
  if (phone && phone.trim()) {
    const digits = normalizePhone(phone);
    return digits ? `+${digits}` : phone;
  }
  // Fallbacks from message metadata
  const anyMsg = message as any;
  const meta = (anyMsg.metadata && typeof anyMsg.metadata === 'object') ? anyMsg.metadata : {};
  const candidates: Array<string | undefined> = [
    anyMsg.sender_phone,
    meta.sender_phone,
    meta.senderName,
    meta.sender_name,
    meta.name,
    meta.from,
    meta.remoteJid,
    anyMsg.senderJid,
    anyMsg.from,
  ];
  const pick = candidates.find(v => typeof v === 'string' && v.trim().length > 0) as string | undefined;
  if (pick) {
    const val = String(pick);
    // Extract phone-like token from WhatsApp JID if present
    const jidMatch = val.match(/^(\d+)(?:@.*)?$/);
    if (jidMatch && jidMatch[1]) return `+${jidMatch[1]}`;
    return val;
  }
  // As last resort, show shortened sender_id
  const sid = anyMsg.sender_id != null ? String(anyMsg.sender_id) : (anyMsg.senderId != null ? String(anyMsg.senderId) : '');
  if (sid) return sid;
  return 'Unknown';
};

const getSenderAvatar = (message: Message): string | null => {
  if (!props.isGroupChat) return null;
  const anyMsg = message as any;
  const sender = findMemberForMessage(message) as any;
  // 1) Prefer avatar from members list
  if (sender?.avatar_url) return sender.avatar_url as string;
  // 2) Fallback: avatar on message itself (various possible keys)
  if (typeof anyMsg.sender_avatar_url === 'string' && anyMsg.sender_avatar_url) return anyMsg.sender_avatar_url;
  if (typeof anyMsg.sender_profile_picture_url === 'string' && anyMsg.sender_profile_picture_url) return anyMsg.sender_profile_picture_url;
  if (typeof anyMsg.senderProfilePictureUrl === 'string' && anyMsg.senderProfilePictureUrl) return anyMsg.senderProfilePictureUrl;
  // 3) Fallback: metadata fields
  const meta = anyMsg.metadata || {};
  if (typeof meta.sender_avatar_url === 'string' && meta.sender_avatar_url) return meta.sender_avatar_url;
  if (typeof meta.sender_profile_picture_url === 'string' && meta.sender_profile_picture_url) return meta.sender_profile_picture_url;
  if (typeof meta.senderProfilePictureUrl === 'string' && meta.senderProfilePictureUrl) return meta.senderProfilePictureUrl;
  // 4) Fallback: lookup contact's profile picture from direct chat
  const phoneCandidates: string[] = [];
  if (typeof anyMsg.sender_phone === 'string') phoneCandidates.push(anyMsg.sender_phone);
  if (typeof meta.sender_phone === 'string') phoneCandidates.push(meta.sender_phone);
  if (typeof meta.remoteJid === 'string') phoneCandidates.push(meta.remoteJid);
  if (typeof anyMsg.senderJid === 'string') phoneCandidates.push(anyMsg.senderJid);
  if (typeof anyMsg.from === 'string') phoneCandidates.push(anyMsg.from);
  const contactChat = findDirectChatByAnyPhone(phoneCandidates) as any;
  const pic = contactChat?.contact_info?.profile_picture_url || contactChat?.avatar_url;
  return pic || null;
};

const formatTime = (dateString: string): string => {
  const date = new Date(dateString);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
};

// Day separator helpers
const isSameDay = (a: Date, b: Date) =>
  a.getFullYear() === b.getFullYear() &&
  a.getMonth() === b.getMonth() &&
  a.getDate() === b.getDate();

const needsDaySeparator = (index: number): boolean => {
  if (!sortedMessages.value || sortedMessages.value.length === 0) return false;
  if (index === 0) return true;
  const prev = new Date(sortedMessages.value[index - 1].created_at);
  const curr = new Date(sortedMessages.value[index].created_at);
  return !isSameDay(prev, curr);
};

const formatDayLabel = (dateString: string): string => {
  const d = new Date(dateString);
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);
  if (isSameDay(d, today)) return 'Today';
  if (isSameDay(d, yesterday)) return 'Yesterday';
  return d.toLocaleDateString([], { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
};

// Backfill reaction_users labels for all messages without it
const backfillReactionUserLabels = () => {
  try {
    messages.value = messages.value.map((m: any) => {
      if (!m || !m.reactions || typeof m.reactions !== 'object') return m;
      const existing: Record<string, string> = m.reaction_users || {};
      const filled: Record<string, string> = { ...existing };
      for (const uid of Object.keys(m.reactions)) {
        if (!filled[uid]) {
          // Resolve via members
          const member = (props.members as any[])?.find?.((mm: any) => String(mm?.id) === String(uid));
          if (member) {
            const name = member.name && String(member.name).trim();
            if (name) filled[uid] = String(member.name);
            else if ((member as any).phone || (member as any).phone_number) {
              const raw = String((member as any).phone || (member as any).phone_number);
              const digits = raw.replace(/@.*$/, '').replace(/\D/g, '');
              if (digits) filled[uid] = `+${digits}`; else filled[uid] = raw;
            }
          }
          // Resolve via prior messages by sender_id
          if (!filled[uid]) {
            const msgByUser = messages.value.find((mm: any) => String(mm?.sender_id) === String(uid));
            if (msgByUser) {
              const direct = (msgByUser as any).sender_name || (msgByUser as any).sender?.name;
              if (direct && String(direct).trim()) filled[uid] = String(direct);
              else {
                try {
                  const candidate = getSenderLabel(msgByUser as any);
                  if (candidate && String(candidate).trim()) filled[uid] = String(candidate);
                } catch {}
              }
              if (!filled[uid]) {
                const meta = (msgByUser as any).metadata || {};
                const raw = meta.sender_phone || meta.remoteJid || (msgByUser as any).sender_phone || '';
                if (raw && typeof raw === 'string') {
                  const digits = raw.replace(/@.*$/, '').replace(/\D/g, '');
                  if (digits) filled[uid] = `+${digits}`;
                }
              }
            }
          }
        }
      }
      if (Object.keys(filled).length > 0 && JSON.stringify(filled) !== JSON.stringify(existing)) {
        return { ...m, reaction_users: filled };
      }
      return m;
    });
  } catch {}
};

// Check if unread indicator is needed before this message
const needsUnreadIndicator = (index: number): boolean => {
  if (!sortedMessages.value || sortedMessages.value.length === 0) return false;
  if (!firstUnreadMessageId.value) return false;
  
  const message = sortedMessages.value[index];
  return message.id === firstUnreadMessageId.value;
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
  try {
    const connected = await connectWebSocket();
    
    if (connected) {
      isConnected.value = true;
      reconnectAttempts.value = 0;
      
      setupWebSocketListeners();
      await fetchLatestMessages();
      markVisibleMessagesAsRead();
      
      return true;
    } else {
      console.error('Failed to establish WebSocket connection');
      throw new Error('Failed to connect to WebSocket');
    }
  } catch (error) {
    console.error('WebSocket initialization failed:', error);
    isConnected.value = false;
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

    // De-duplicate against existing messages and normalize
    const existingIds = new Set((messages.value || []).map(m => m?.id).filter(Boolean));
    const uniqueNewMessages = (newMessages || []).filter((m: any) => m && m.id && !existingIds.has(m.id));
    const normalizedNew = uniqueNewMessages.map(normalizeMessage);

    if (normalizedNew.length > 0) {
      // Add new messages to the beginning of the array
      messages.value = [...normalizedNew, ...messages.value];
      // Update cursor to the oldest loaded message id
      lastMessageId.value = normalizedNew[0].id;
      // Ensure reaction tooltips have proper labels
      backfillReactionUserLabels();
      
      // Check if there are more messages to load
      hasMoreMessages.value = normalizedNew.length === 20;
      
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
  
  // Mark that initial load is complete after first scroll (only set timeout once)
  if (!initialLoadTimeoutSet) {
    initialLoadTimeoutSet = true;
    setTimeout(() => {
      isInitialLoad = false;
    }, 2000);
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
           !isMine(msg)
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
  }, 2000); // Poll every 2 seconds
};

// Process and normalize message data to ensure required fields exist
const normalizeMessage = (msg: any): Message => {
  
  // Debug: Log if this is a deleted message
  if (msg.deleted_at) {
    console.log('[DEBUG normalizeMessage] Deleted message:', { 
      id: msg.id, 
      type: msg.type, 
      content: msg.content, 
      deleted_at: msg.deleted_at 
    });
  }
  
  // Safely interpret is_from_me from various backend types
  const parseIsFromMe = (val: unknown): boolean | undefined => {
    if (val === undefined || val === null) return undefined;
    if (typeof val === 'boolean') return val;
    if (typeof val === 'number') return val === 1;
    if (typeof val === 'string') {
      const lower = val.trim().toLowerCase();
      if (lower === 'true' || lower === '1' || lower === 'yes') return true;
      if (lower === 'false' || lower === '0' || lower === 'no') return false;
    }
    return undefined;
  };

  const normalizedIsFromMe = parseIsFromMe((msg as any).is_from_me);
  const fallbackMine = ((msg as any).sender === 'me')
    || (msg?.sender_id?.toString?.() === props.currentUser.id?.toString());
  const normalizedIsMine = (typeof normalizedIsFromMe === 'boolean') ? normalizedIsFromMe : !!fallbackMine;

  const normalized: any = {
    id: msg.id?.toString() || '',
    content: msg.content !== undefined ? msg.content : '',
    sender_id: msg.sender_id || msg.sender || 'unknown',
    sender_phone: msg.sender_phone || undefined,
    chat_id: msg.chat_id || msg.chat || 'unknown',
    type: msg.type || 'text',
    direction: msg.direction || 'incoming',
    status: msg.status || 'sent',
    created_at: msg.created_at || new Date().toISOString(),
    updated_at: msg.updated_at || new Date().toISOString(),
    deleted_at: msg.deleted_at || undefined,
    edited_at: msg.edited_at || undefined,
    read_by: Array.isArray(msg.read_by) ? msg.read_by : [],
    media: msg.media || null,
    mimetype: msg.mimetype || null,
    filename: msg.filename || undefined,
    size: msg.size || undefined,
    reactions: msg.reactions || {},
    reaction_users: msg.reaction_users || undefined,
    metadata: msg.metadata || {},
    // IMPORTANT: Preserve reply/quote data
    reply_to_message_id: msg.reply_to_message_id || undefined,
    quoted_message: msg.quoted_message || undefined,
    reply_to_message: msg.reply_to_message || undefined,
    // Preserve backend flag for alignment/styling using safe boolean parsing
    ...(normalizedIsFromMe !== undefined ? { is_from_me: normalizedIsFromMe } : {}),
    // Stable flag the template can rely on
    is_mine: normalizedIsMine,
  };
  // Derive reaction_users if not provided by API
  if (!normalized.reaction_users && normalized.reactions && typeof normalized.reactions === 'object') {
    const mapping: Record<string, string> = {};
    try {
      const members: Array<any> = (props as any).members || [];
      for (const uid of Object.keys(normalized.reactions)) {
        const found = members.find(m => String(m.id) === String(uid));
        if (found) {
          const label = (found.name && String(found.name).trim()) ? String(found.name) : (found.phone || found.phone_number || '');
          if (label) mapping[String(uid)] = String(label);
        }
      }
    } catch {}
    if (Object.keys(mapping).length > 0) {
      normalized.reaction_users = mapping;
    }
  }
  
  return normalized;
};

// Fetch latest messages with deduplication and proper ordering
const fetchLatestMessages = async () => {
  if (!props.chat) {
    loading.value = false;
    return;
  }
  
  try {
    // Only show loading spinner on the very first load (when messages array is empty)
    // Don't show it during polling or after initial load
    if (isInitialLoad && messages.value.length === 0) {
      loading.value = true;
    }
    
    const response = await apiClient.get(`/chats/${props.chat}/messages/latest`, {
      params: {
        after: messages.value?.[messages.value?.length - 1]?.id || null
      }
    });
    
    // Process and normalize the messages
    const newMessages = Array.isArray(response?.data?.data) 
      ? response.data.data.map(normalizeMessage) 
      : [];
    
    // Set loading to false as soon as we have the response
    loading.value = false;
    
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

        // Backfill reaction_users labels for all messages without it
        backfillReactionUserLabels();

        // Maintain a correct 'before' cursor (oldest loaded message)
        if (messages.value.length > 0) {
          lastMessageId.value = messages.value[0]?.id || lastMessageId.value;
        }
        
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
              !isMine(msg) &&
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
    // Always set loading to false after fetching, regardless of isInitialLoad
    loading.value = false;
    // Don't set isInitialLoad to false here - let the scroll handler control it after 2 seconds
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

// Reaction handlers
const handleAddReaction = async (payload: { messageId: string | number; emoji: string }) => {
  try {
    const response = await apiClient.post(`/messages/${payload.messageId}/reactions`, {
      user_id: props.currentUser.id,
      reaction: payload.emoji
    });
    
    if (response.data.status === 'success') {
      // Update local message with new reactions
      const messageIndex = messages.value.findIndex(m => m.id === payload.messageId);
      if (messageIndex !== -1) {
        // Use Vue's reactivity by creating a new object
        messages.value[messageIndex] = {
          ...messages.value[messageIndex],
          reactions: response.data.data.reactions
        };
      }
    }
  } catch (error) {
    console.error('Error adding reaction:', error);
  }
};

const handleRemoveReaction = async (payload: { messageId: string | number }) => {
  try {
    const response = await apiClient.delete(`/messages/${payload.messageId}/reactions/${props.currentUser.id}`);
    
    if (response.data.status === 'success') {
      // Update local message with new reactions
      const messageIndex = messages.value.findIndex(m => m.id === payload.messageId);
      if (messageIndex !== -1) {
        // Use Vue's reactivity by creating a new object
        messages.value[messageIndex] = {
          ...messages.value[messageIndex],
          reactions: response.data.data.reactions
        };
      }
    }
  } catch (error) {
    console.error('Error removing reaction:', error);
  }
};

// Reply handler
const handleReplyToMessage = (message: any) => {
  emit('reply-to-message', message);
};

// Edit message handler
const handleEditMessage = (message: any) => {
  emit('edit-message', message);
};

// Delete message handler
const handleDeleteMessage = async (messageId: string | number) => {
  try {
    // Immediately update the message to show deletion placeholder
    const messageIndex = messages.value.findIndex(m => m.id === messageId);
    if (messageIndex !== -1) {
      messages.value[messageIndex] = {
        ...messages.value[messageIndex],
        content: '[Gelöschte Nachricht]',
        type: 'deleted',
        deleted_at: new Date().toISOString()
      } as any;
    }
    
    // Send delete request to backend
    await apiClient.delete(`/messages/${messageId}`);
    
    console.log('Message deleted successfully');
  } catch (error) {
    console.error('Failed to delete message:', error);
    alert('Fehler beim Löschen der Nachricht');
    
    // Revert the message if deletion failed
    // You might want to refetch the message here
  }
};

// Image preview handlers
const handleOpenImagePreview = (payload: { src: string; caption?: string }) => {
  // Collect all images from the current chat
  const allImages = messages.value
    .filter(msg => msg.type === 'image' || msg.mimetype?.startsWith('image/'))
    .map(resolveMessageImageSrc)
    .filter((src): src is string => Boolean(src));
  
  imageList.value = allImages;
  previewImageSrc.value = payload.src;
  previewImageCaption.value = payload.caption || '';
  currentImageIndex.value = allImages.indexOf(payload.src);

  if (currentImageIndex.value === -1) {
    currentImageIndex.value = Math.max(allImages.indexOf(previewImageSrc.value), 0);
  }
  
  imagePreviewOpen.value = true;
};

const closeImagePreview = () => {
  imagePreviewOpen.value = false;
};

const updateImageIndex = (index: number) => {
  if (index >= 0 && index < imageList.value.length) {
    currentImageIndex.value = index;
    previewImageSrc.value = imageList.value[index];

    // Find the message with this image to get its caption
    const message = messages.value.find(msg => resolveMessageImageSrc(msg) === previewImageSrc.value);
    previewImageCaption.value = message?.content || '';
  }
};

const resolveMessageImageSrc = (message: Message): string => {
  const media = message.media as MediaObject | string | null | undefined;

  const normalizePath = (value?: string | null): string => {
    if (!value || typeof value !== 'string') return '';
    return /^https?:\/\//.test(value) ? value : `/storage/${value}`;
  };

  if (typeof media === 'string' && media.length > 0) {
    return normalizePath(media);
  }

  if (media && typeof media === 'object') {
    const candidates = [media.thumbnail_url, media.path, media.url];
    for (const candidate of candidates) {
      const normalized = normalizePath(candidate);
      if (normalized) {
        return normalized;
      }
    }
  }

  if (typeof message.media_url === 'string' && message.media_url.length > 0) {
    return normalizePath(message.media_url);
  }

  const metadataMediaPath = (() => {
    const metadata = message.metadata as MediaObject | string | null | undefined;
    if (!metadata) return null;
    if (typeof metadata === 'string') {
      try {
        const parsed = JSON.parse(metadata);
        return parsed?.media_path ?? null;
      } catch (error) {
        return null;
      }
    }
    return metadata?.media_path ?? null;
  })();

  if (typeof metadataMediaPath === 'string' && metadataMediaPath.length > 0) {
    return normalizePath(metadataMediaPath);
  }

  return '';
};

// Lifecycle hooks
async function handleVisibilityChange() {
  if (document.visibilityState === 'visible') {
    markVisibleMessagesAsRead();
  } else if (document.visibilityState === 'hidden') {
    // Save last read message when page becomes hidden
    if (props.chat && messages.value.length > 0) {
      const lastMessage = messages.value[messages.value.length - 1];
      if (lastMessage && lastMessage.id) {
        try {
          await apiClient.post(`/chats/${props.chat}/last-read`, {
            message_id: lastMessage.id
          });
        } catch (error) {
          console.error('Failed to save last read on visibility change:', error);
        }
      }
    }
  }
}

function handleWindowFocus() {
  markVisibleMessagesAsRead();
}

// Save last read message before page unloads
const handleBeforeUnload = () => {
  if (props.chat && messages.value.length > 0) {
    const lastMessage = messages.value[messages.value.length - 1];
    if (lastMessage && lastMessage.id) {
      // Use fetch with keepalive for reliable delivery during page unload
      try {
        fetch(`${import.meta.env.VITE_API_BASE_URL}/chats/${props.chat}/last-read`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ message_id: lastMessage.id }),
          keepalive: true // Ensures request completes even if page is closing
        });
      } catch (error) {
        console.error('Failed to save on unload:', error);
      }
    }
  }
};

onMounted(async () => {
  if (props.chat) {
    try {
      // Add event listeners for visibility and focus
      window.addEventListener('visibilitychange', handleVisibilityChange);
      window.addEventListener('focus', handleWindowFocus);
      window.addEventListener('beforeunload', handleBeforeUnload);
      
      await initWebSocket();
      
      // Always start polling as a fallback mechanism
      startPolling();
      
      loading.value = false;
    } catch (error) {
      console.error('Error initializing MessageList:', error);
      
      loading.value = false;
      
      // Start polling as fallback
      try {
        await fetchLatestMessages();
        startPolling();
      } catch (fetchError) {
        console.error('Error in fallback polling:', fetchError);
      }
    }
  }
});

// Watch for chat changes and scroll to bottom when messages load
watch(() => props.chat, async (newChatId, oldChatId) => {
  if (newChatId && newChatId !== oldChatId) {
    
    // Save the last read message for the old chat before switching
    if (oldChatId && messages.value.length > 0) {
      const lastMessage = messages.value[messages.value.length - 1];
      if (lastMessage && lastMessage.id) {
        try {
          await apiClient.post(`/chats/${oldChatId}/last-read`, {
            message_id: lastMessage.id
          });
        } catch (error) {
          console.error('Failed to save last read message:', error);
        }
      }
    }
    
    // Reset messages first
    messages.value = [];
    lastReadMessageId.value = null;
    firstUnreadMessageId.value = null;
    isInitialLoad = true; // Reset initial load flag for new chat
    initialLoadTimeoutSet = false; // Reset timeout flag for new chat
    
    // Load the last read message ID from database
    try {
      const response = await apiClient.get(`/chats/${newChatId}/last-read`);
      if (response.data && response.data.last_read_message_id) {
        lastReadMessageId.value = response.data.last_read_message_id;
      }
    } catch (error) {
      console.error('Failed to load last read message:', error);
    }
    
    // Wait for messages to be fetched
    await nextTick();
    // Give more time for the DOM to update and render all messages
    setTimeout(() => {
      scrollToBottom({ behavior: 'auto' });
    }, 800);
  }
});

// Watch for messages being loaded initially and scroll to bottom
watch(messages, (newMessages, oldMessages) => {
  // Only scroll if we're going from no messages to having messages (initial load)
  if (oldMessages.length === 0 && newMessages.length > 0) {
    nextTick(() => {
      scrollToBottom({ behavior: 'auto' });
    });
  }
}, { deep: true });

// Watch for sorted messages to calculate unread indicator
watch(sortedMessages, (sorted) => {
  if (sorted.length === 0) {
    return;
  }
  
  // If there's a lastReadMessageId, show indicator after that message
  if (lastReadMessageId.value) {
    const lastReadIndex = sorted.findIndex(m => m.id === lastReadMessageId.value);
    
    if (lastReadIndex !== -1 && lastReadIndex < sorted.length - 1) {
      // The first unread message is the one after the last read message
      const firstUnread = sorted[lastReadIndex + 1];
      const isFromMe = isMine(firstUnread);
      
      if (firstUnread && !isFromMe) {
        firstUnreadMessageId.value = firstUnread.id;
      } else {
        firstUnreadMessageId.value = null;
      }
    } else {
      firstUnreadMessageId.value = null;
    }
  } else {
    // No lastReadMessageId means first time opening this chat
    // Show indicator for the first message that's not from me
    const firstNotFromMe = sorted.find(m => !isMine(m));
    if (firstNotFromMe) {
      firstUnreadMessageId.value = firstNotFromMe.id;
    }
  }
}, { deep: true });

// Clean up on unmount
onUnmounted(async () => {
  // Save last read message before unmounting
  if (props.chat && messages.value.length > 0) {
    const lastMessage = messages.value[messages.value.length - 1];
    if (lastMessage && lastMessage.id) {
      try {
        await apiClient.post(`/chats/${props.chat}/last-read`, {
          message_id: lastMessage.id
        });
      } catch (error) {
        console.error('Failed to save last read message on unmount:', error);
      }
    }
  }
  
  // Clean up any remaining timeouts or intervals
  Object.entries(typingTimeouts.value).forEach(([userId, timeoutId]) => {
    clearTimeout(timeoutId);
  });
  
  // Clear polling interval
  if (pollInterval.value) {
    clearInterval(pollInterval.value);
  }
  
  // Clear reconnect timeout
  if (reconnectTimeout.value) {
    clearTimeout(reconnectTimeout.value);
  }
  
  // Disconnect WebSocket
  disconnectWebSocket();
  
  // Remove event listeners
  window.removeEventListener('visibilitychange', handleVisibilityChange);
  window.removeEventListener('focus', handleWindowFocus);
  window.removeEventListener('beforeunload', handleBeforeUnload);
});

// Handle WebSocket disconnection and reconnection
const handleDisconnect = () => {
  isConnected.value = false;
  
  if (reconnectAttempts.value < maxReconnectAttempts) {
    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts.value), 30000); // Exponential backoff with max 30s
    reconnectAttempts.value++;
    
    reconnectTimeout.value = window.setTimeout(() => {
      initWebSocket();
    }, delay);
  } else {
    console.error('Max reconnection attempts reached');
    // Notify user about connection issues
  }
};

// Set up WebSocket event listeners
const setupWebSocketListeners = () => {
  if (!props.chat) {
    const errorMsg = 'Cannot set up WebSocket listeners: No chat ID provided';
    console.error(errorMsg);
    throw new Error(errorMsg);
  }
  
  try {
    // Listen for new messages
    const newMessageUnsubscribe = listenForNewMessages(props.chat.toString(), (message: any) => {
      if (!message) {
        return;
      }
      
      // Initialize messages array if it's undefined or not an array
      if (!Array.isArray(messages.value)) {
        messages.value = [];
      }
      
      try {
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
          if (!isMine(normalizedMessage)) {
            markMessagesAsRead([normalizedMessage.id || normalizedMessage.temp_id].filter(Boolean) as string[]);
          }
        }
      } catch (error) {
        console.error('Error processing new message:', error);
      }
    });
    
    // Listen for typing indicators
    const typingUnsubscribe = listenForTyping(props.chat.toString(), (event: any) => {
      if (!event || !event.user_id) {
        return;
      }
      
      // Check both is_typing and typing for backward compatibility
      const isTypingNow = event.is_typing ?? event.typing ?? false;
      
      if (isTypingNow) {
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
    });
    
    // Listen for read receipts
    const readReceiptUnsubscribe = listenForReadReceipts(props.chat.toString(), (event: any) => {
      if (!event || !event.message_id) {
        return;
      }
      
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
    });
    
    // Listen for reaction updates
    const reactionUnsubscribe = listenForReactionUpdates(props.chat.toString(), (event: any) => {
      if (!event || !event.message_id) {
        return;
      }
      
      // Update the message with new reactions
      const messageIndex = messages.value.findIndex(m => String(m.id) === String(event.message_id));
      if (messageIndex !== -1) {
        const message = messages.value[messageIndex];
        let updatedReactions = message.reactions || {};
        let updatedReactionUsers: Record<string, string> = (message as any).reaction_users || {};
        
        if (typeof updatedReactions === 'string') {
          updatedReactions = {};
        }
        
        if (event.added) {
          // Add or update reaction
          updatedReactions = {
            ...updatedReactions,
            [event.user.id]: event.reaction
          };

          // Store a human-readable label for tooltips
          const existing = updatedReactionUsers[String(event.user.id)];
          if (!existing) {
            let label = (event.user && event.user.name) ? String(event.user.name) : '';
            if (!label || label.trim().length === 0) {
              const member = (props.members || []).find((m: any) => String(m.id) === String(event.user.id));
              if (member) {
                label = member.name || member.phone || member.phone_number || '';
              }
            }
            if (!label || label.trim().length === 0) {
              const msgByUser = messages.value.find((mm: any) => String(mm?.sender_id) === String(event.user.id));
              if (msgByUser) {
                try {
                  const candidate = getSenderLabel(msgByUser as any);
                  if (candidate && String(candidate).trim()) label = String(candidate);
                } catch {}
                if (!label || label.trim().length === 0) {
                  const meta = (msgByUser as any).metadata || {};
                  const raw = meta.sender_phone || meta.remoteJid || (msgByUser as any).sender_phone || '';
                  if (raw && typeof raw === 'string') {
                    const digits = raw.replace(/@.*$/, '').replace(/\D/g, '');
                    if (digits) label = `+${digits}`;
                  }
                }
              }
            }
            updatedReactionUsers = { ...updatedReactionUsers };
            if (label && label.trim().length > 0) {
              updatedReactionUsers[String(event.user.id)] = label;
            }
          }
        } else {
          // Remove reaction
          updatedReactions = { ...updatedReactions };
          delete updatedReactions[event.user.id];
          if (updatedReactionUsers && updatedReactionUsers[String(event.user.id)]) {
            updatedReactionUsers = { ...updatedReactionUsers };
            delete updatedReactionUsers[String(event.user.id)];
          }
        }
        
        // Use Vue's reactivity by creating a new object
        messages.value[messageIndex] = {
          ...message,
          reactions: updatedReactions,
          reaction_users: updatedReactionUsers
        };
      }
    });
    
    // Listen for message edited events
    const editedUnsubscribe = listenForMessageEdited(props.chat.toString(), (event: any) => {
      if (!event || !event.message_id) {
        return;
      }
      
      // Update the message content
      const messageIndex = messages.value.findIndex(m => String(m.id) === String(event.message_id));
      if (messageIndex !== -1) {
        messages.value[messageIndex] = {
          ...messages.value[messageIndex],
          content: event.content,
          edited_at: event.edited_at
        } as any;
      }
    });
    
    // Listen for message deleted events
    const deletedUnsubscribe = listenForMessageDeleted(props.chat.toString(), (event: any) => {
      if (!event || !event.message_id) {
        return;
      }
      
      // Find and update the message to show deletion placeholder
      const messageIndex = messages.value.findIndex(m => String(m.id) === String(event.message_id));
      if (messageIndex !== -1) {
        // Replace with deletion placeholder
        messages.value[messageIndex] = {
          ...messages.value[messageIndex],
          content: '[Gelöschte Nachricht]',
          type: 'deleted',
          deleted_at: event.deleted_at
        } as any;
      }
    });
    
    // Store unsubscribe functions
    const unsubscribeFunctions = {
      newMessage: newMessageUnsubscribe,
      typing: typingUnsubscribe,
      readReceipt: readReceiptUnsubscribe,
      reaction: reactionUnsubscribe,
      edited: editedUnsubscribe,
      deleted: deletedUnsubscribe
    };
    
    // Clean up WebSocket listeners on unmount
    return () => {
      Object.entries(unsubscribeFunctions).forEach(([name, unsubscribe]) => {
        if (typeof unsubscribe === 'function') {
          try {
            unsubscribe();
          } catch (err) {
            // Unsubscribe error
          }
        }
      });
    };
  } catch (error) {
    console.error('Error setting up WebSocket listeners:', error);
    throw error;
  }
};

// Fetch messages from API
const fetchMessages = async (params: { chatId: string; limit: number; before?: string }): Promise<{ messages: Message[]; hasMore: boolean }> => {
  try {
    const response = await apiClient.get('/messages', { params });
    
    // Debug: Log raw API response
    console.log('[DEBUG] Raw API messages:', response.data.data?.slice(0, 3));
    
    // Ensure messages are properly typed and have required fields
    const messages = (response.data.data || []).map((msg: any) => {
      // Debug: Log deleted messages
      if (msg.deleted_at) {
        console.log('[DEBUG] Deleted message from API:', { 
          id: msg.id, 
          type: msg.type, 
          content: msg.content, 
          deleted_at: msg.deleted_at 
        });
      }
      
      // Use the message as-is from the API, only add fallbacks for truly missing fields
      const base: any = {
        id: msg.id || '',
        sender_id: msg.sender_id || '',
        chat_id: msg.chat_id || params.chatId,
        created_at: msg.created_at || new Date().toISOString(),
        updated_at: msg.updated_at || new Date().toISOString(),
        status: msg.status || 'sent',
        read_by: Array.isArray(msg.read_by) ? msg.read_by : [],
        // Spread all other fields from API (including type, content, deleted_at, etc.)
        ...msg
      };
      // Backfill reaction_users if missing
      if (!base.reaction_users && base.reactions && typeof base.reactions === 'object') {
        const mapping: Record<string, string> = {};
        try {
          const members: Array<any> = (props as any).members || [];
          for (const uid of Object.keys(base.reactions)) {
            const found = members.find((m: any) => String(m.id) === String(uid));
            if (found) {
              const label = (found.name && String(found.name).trim()) ? String(found.name) : (found.phone || found.phone_number || '');
              if (label) mapping[String(uid)] = String(label);
            }
          }
        } catch {}
        if (Object.keys(mapping).length > 0) {
          base.reaction_users = mapping;
        }
      }
      return base;
    });
    
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

// Expose methods to parent component
defineExpose({
  scrollToBottom,
  reload: fetchLatestMessages,
  addTemporaryMessage: (msg: any) => {
    messages.value.push(msg);
  },
  removeTemporaryMessage: () => {
    messages.value = messages.value.filter(m => !(m as any).isTemporary);
  },
  setLastReadMessageId: (messageId: string) => {
    lastReadMessageId.value = messageId;
  },
  scrollContainer,
  isScrolledToBottom
});

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
