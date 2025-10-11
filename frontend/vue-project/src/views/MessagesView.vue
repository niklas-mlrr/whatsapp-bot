<template>
  <div class="h-screen bg-gray-100 flex flex-row overflow-hidden">
    <!-- Sidebar -->
    <aside class="w-80 bg-white border-r border-gray-200 flex flex-col h-full">
      <div class="p-4 font-bold text-lg border-b border-gray-200">Chats</div>
      <div class="flex-1 overflow-y-auto">
        <div v-if="loadingChats" class="text-blue-500 p-4">Loading chats...</div>
        <div v-if="errorChats" class="text-red-500 p-4">{{ errorChats }}</div>
        <ul v-if="!loadingChats && !errorChats">
          <li v-for="chat in chats" :key="chat.id" 
              :class="['cursor-pointer px-4 py-3 border-b border-gray-100 hover:bg-green-50 relative group flex items-center justify-between', selectedChat && selectedChat.id === chat.id ? 'bg-green-100 font-bold' : '']">
            <span @click="selectChat(chat)" class="flex-1">{{ chat.name }}</span>
            <button 
              @click.stop="confirmDeleteChat(chat)"
              class="opacity-0 group-hover:opacity-100 transition-opacity p-2 hover:bg-red-100 rounded-full"
              title="Delete chat"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </li>
        </ul>
      </div>
    </aside>
    <!-- Main chat area -->
    <main class="flex-1 flex flex-col h-full overflow-hidden">
      <!-- Chat header -->
      <div class="flex items-center gap-3 px-6 py-4 bg-white border-b border-gray-200 shadow-sm min-h-[64px] sticky top-0 z-10">
        <div class="w-10 h-10 rounded-full bg-green-300 flex items-center justify-center text-green-700 font-bold text-lg">
          <span v-if="selectedChat">{{ selectedChat.name.slice(0,2).toUpperCase() }}</span>
        </div>
        <div class="flex flex-col">
          <span class="font-semibold text-lg text-gray-900">{{ selectedChat ? selectedChat.name : 'Select a chat' }}</span>
          <span class="text-xs text-gray-400">Online</span>
        </div>
      </div>
      
      <!-- Messages area -->
      <div class="flex-1 overflow-y-auto">
        <MessageList 
          v-if="selectedChat"
          ref="messageListRef" 
          :chat="selectedChat.id"
          :current-user="currentUserForChat"
          :is-group-chat="!!selectedChat.is_group"
          :members="membersForChat"
        />
        <div v-else class="flex items-center justify-center h-full text-gray-500">
          Select a chat to start messaging
        </div>
      </div>
      
      <!-- Message input -->
      <div class="sticky bottom-0 bg-white border-t border-gray-200 z-10">
        <!-- Attachment preview -->
        <div v-if="attachmentPreviewVisible" class="relative bg-gray-50 p-3 border-b border-gray-200">
          <div v-if="isImageAttachment" class="max-w-[220px] relative">
            <img :src="attachmentPreviewUrl || undefined" alt="Preview" class="max-h-36 rounded-lg" />
            <button
              type="button"
              @click="clearAttachment"
              class="absolute -right-2 -top-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition-colors"
              title="Remove attachment"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <div v-if="isUploadingAttachment" class="absolute inset-x-0 bottom-2 flex justify-center">
              <span class="text-xs bg-black bg-opacity-60 text-white px-2 py-0.5 rounded-full">Uploading...</span>
            </div>
          </div>
          <div v-else class="relative flex items-start gap-3 bg-white border border-gray-200 rounded-lg p-4 pr-12 max-w-lg">
            <div class="p-2 bg-gray-100 rounded-full">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10l5 5m0 0l5-5m-5 5V6" />
              </svg>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800 truncate">{{ attachmentDisplayName }}</p>
              <p class="text-xs text-gray-500">
                {{ formattedAttachmentSize }}
                <span v-if="attachmentDisplayMimetype"> • {{ attachmentDisplayMimetype }}</span>
              </p>
              <p v-if="isUploadingAttachment" class="text-xs text-blue-500 mt-1">Uploading...</p>
            </div>
            <button
              type="button"
              @click="clearAttachment"
              class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition-colors"
              title="Remove attachment"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
        
        <form @submit.prevent="sendMessageHandler" class="flex items-center gap-2 px-6 py-4 relative">
        <input
          v-model="input"
          :disabled="!selectedChat"
          type="text"
          placeholder="Type a message"
          class="flex-1 rounded-full border border-gray-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-400 disabled:bg-gray-100 disabled:cursor-not-allowed"
        />
        <div class="relative">
          <button type="button" @click="openMenu" :disabled="!selectedChat"
            class="inline-block w-10 h-10 bg-green-500 rounded-full flex items-center justify-center text-white ml-2 focus:outline-none disabled:bg-gray-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
          </button>
          <div v-if="showMenu" class="absolute left-0 bottom-12 z-10 bg-white border border-gray-200 rounded shadow-lg min-w-[160px]">
            <ul>
              <li @click="selectAddImage" class="px-4 py-2 hover:bg-green-50 cursor-pointer">Add image</li>
              <li @click="selectAddFile" class="px-4 py-2 hover:bg-green-50 cursor-pointer">Add file</li>
              <!-- Future: <li class='px-4 py-2 hover:bg-green-50 cursor-pointer'>Create poll</li> -->
            </ul>
          </div>
        </div>
        <input id="image-upload-input" type="file" accept="image/*" class="hidden" @change="onAttachmentChange" :disabled="!selectedChat" />
        <input id="file-upload-input" type="file" class="hidden" @change="onAttachmentChange" :disabled="!selectedChat" />
        <button
          type="submit"
          :disabled="!canSend"
          class="bg-green-500 text-white rounded-full px-6 py-2 font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed flex items-center gap-2 min-w-[80px] justify-center"
        >
          <svg v-if="isSending" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>{{ isSending ? 'Sending...' : 'Send' }}</span>
        </button>
        </form>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import MessageList from '../components/MessageList.vue'
import { fetchChats, sendMessage, uploadFile, deleteChat } from '../api/messages'

// Base current user from auth (fallback)
const currentUser = ref({
  id: '1', // Replace with auth user id
  name: 'Current User',
  email: 'user@example.com',
  avatar: null,
  is_online: true,
  last_seen: new Date().toISOString()
})

const chats = ref<any[]>([])
const loadingChats = ref(false)
const errorChats = ref<string | null>(null)
const selectedChat = ref<any | null>(null)
const input = ref('')
const attachmentFile = ref<File | null>(null)
const attachmentPath = ref<string | null>(null)
const attachmentMimetype = ref<string | null>(null)
const attachmentPreviewUrl = ref<string | null>(null)
const attachmentName = ref<string | null>(null)
const attachmentSize = ref<number | null>(null)
const isUploadingAttachment = ref(false)
const showMenu = ref(false)
const isSending = ref(false)

const messageListRef = ref<any>(null)

// Watch for changes to input field to debug if image is being cleared
watch(input, (newVal: string, oldVal: string) => {
  console.log('Input changed:', {
    newVal,
    oldVal,
    attachmentPath: attachmentPath.value,
    attachmentPreviewUrl: attachmentPreviewUrl.value,
    attachmentMimetype: attachmentMimetype.value
  })
})

const isImageAttachment = computed(() => {
  const mime = attachmentMimetype.value || attachmentFile.value?.type || ''
  return mime.startsWith('image/')
})

const attachmentPreviewVisible = computed(() => !!attachmentFile.value || !!attachmentPath.value || isUploadingAttachment.value)

const attachmentDisplayName = computed(() => attachmentName.value || attachmentFile.value?.name || 'Attachment')

const attachmentDisplayMimetype = computed(() => attachmentMimetype.value || attachmentFile.value?.type || '')

const formattedAttachmentSize = computed(() => {
  const fallbackSize = attachmentFile.value?.size
  const size = typeof attachmentSize.value === 'number' && !Number.isNaN(attachmentSize.value)
    ? attachmentSize.value
    : (typeof fallbackSize === 'number' ? fallbackSize : null)

  if (size === null) return 'Unknown size'
  if (size === 0) return '0 Bytes'
  const units = ['Bytes', 'KB', 'MB', 'GB', 'TB']
  const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1)
  const value = size / Math.pow(1024, index)
  const formatted = value >= 10 ? value.toFixed(0) : value.toFixed(1)
  return `${formatted} ${units[index]}`
})

// Computed property to determine if we can send a message
const canSend = computed(() => {
  const hasText = !!(input.value && input.value.trim().length > 0)
  const hasAttachment = !!attachmentPath.value
  const hasChat = !!selectedChat.value
  const notSending = !isSending.value
  const notUploading = !isUploadingAttachment.value

  const result = (hasText || hasAttachment) && hasChat && notSending && notUploading
  console.log('canSend check:', {
    hasText,
    hasAttachment,
    hasChat,
    notSending,
    notUploading,
    result,
    inputValue: input.value,
    attachmentPathValue: attachmentPath.value
  })

  return result
})

// Normalized members for the selected chat (id, name, phone)
type Member = { id: string; name: string; phone?: string }
const membersForChat = computed<Member[]>(() => {
  const participants = selectedChat.value?.participants || []
  return participants.map((p: any) => ({
    id: p?.id?.toString?.() || String(p?.id ?? ''),
    name: String(p?.name ?? ''),
    phone: p?.phone ?? p?.phone_number
  }))
})

// Derive current user for this chat: the member with phone === 'me'
const currentUserForChat = computed(() => {
  const me = membersForChat.value.find(m => m.phone === 'me')
  if (me) {
    return { id: me.id, name: 'You' }
  }
  // Fallback to auth currentUser
  return { id: currentUser.value.id, name: currentUser.value.name }
})

function selectChat(chat: any) {
  selectedChat.value = chat
  // Scroll to bottom after chat loads
  setTimeout(() => {
    if (messageListRef.value && messageListRef.value.scrollToBottom) {
      messageListRef.value.scrollToBottom()
    }
  }, 300)
}

async function sendMessageHandler() {
  if ((!input.value && !attachmentPath.value) || !selectedChat.value || isSending.value) return

  console.log('=== START sendMessageHandler ===');
  console.log('Current state before storing:', {
    inputValue: input.value,
    attachmentPathValue: attachmentPath.value,
    attachmentMimetypeValue: attachmentMimetype.value,
    attachmentNameValue: attachmentName.value,
    attachmentSizeValue: attachmentSize.value
  });
  
  // Store the message content before clearing
  const messageContent = input.value
  const messageAttachmentPath = attachmentPath.value
  const messageAttachmentMimetype = attachmentMimetype.value
  const messageAttachmentFile = attachmentFile.value
  const messageAttachmentPreviewUrl = attachmentPreviewUrl.value
  const messageAttachmentName = attachmentName.value
  const messageAttachmentSize = attachmentSize.value
  
  console.log('Stored values:', {
    messageContent,
    messageAttachmentPath,
    messageAttachmentMimetype,
    messageAttachmentName,
    messageAttachmentSize
  });
  
  isSending.value = true
  
  try {
    // Determine message type based on mimetype
    let messageType = 'text';
    if (messageAttachmentPath && messageAttachmentMimetype) {
      if (messageAttachmentMimetype.startsWith('image/')) {
        messageType = 'image';
      } else if (messageAttachmentMimetype.startsWith('video/')) {
        messageType = 'video';
      } else if (messageAttachmentMimetype.startsWith('audio/')) {
        messageType = 'audio';
      } else {
        messageType = 'document';
      }
    }
    
    let payload: any = {
      sender: 'me',
      chat: selectedChat.value.name,
      type: messageType,
      content: messageContent || '',  // Ensure content is at least an empty string
    }
    
    console.log('Payload before adding media:', payload);
    
    // If we have an image, include the media path and mimetype
    // The image was already uploaded in onImageChange, so we just use the path
    if (messageAttachmentPath) {
      payload.media = messageAttachmentPath;
      payload.mimetype = messageAttachmentMimetype || undefined;
      payload.filename = messageAttachmentName || undefined;
      payload.size = typeof messageAttachmentSize === 'number' ? messageAttachmentSize : undefined;
      console.log('Added attachment to payload:', { media: payload.media, mimetype: payload.mimetype, filename: payload.filename, size: payload.size });
    }
    
    console.log('Final payload to send:', payload);
    
    // Clear input immediately to show responsiveness
    input.value = ''
    clearAttachmentState()
    
    // Add temporary "sending" message to the chat
    if (messageListRef.value && messageListRef.value.addTemporaryMessage) {
      messageListRef.value.addTemporaryMessage({
        id: 'temp-' + Date.now(),
        sender: 'me',
        chat: selectedChat.value.id,
        type: messageType,
        content: messageContent || '',  // Empty string if no caption
        media: messageAttachmentPath || undefined,
        mimetype: messageAttachmentMimetype || undefined,
        filename: messageAttachmentName || undefined,
        size: typeof messageAttachmentSize === 'number' ? messageAttachmentSize : undefined,
        sending_time: new Date().toISOString(),
        created_at: new Date().toISOString(),
        isTemporary: true,
        isSending: true
      })
    }
    
    await sendMessage(payload)
    
    // Remove temporary message and reload to show the real message
    if (messageListRef.value && messageListRef.value.removeTemporaryMessage) {
      messageListRef.value.removeTemporaryMessage()
    }
    
    // Reload messages for the current chat and scroll to bottom
    if (messageListRef.value && messageListRef.value.reload) {
      await messageListRef.value.reload()
      // Small delay to ensure message is loaded before scrolling
      setTimeout(() => {
        if (messageListRef.value && messageListRef.value.scrollToBottom) {
          messageListRef.value.scrollToBottom()
        }
      }, 100)
    }
  } catch (e: any) {
    console.error('Error sending message:', e);
    console.error('Error response:', e?.response?.data);
    
    // Restore the input values on error
    input.value = messageContent
    attachmentPath.value = messageAttachmentPath
    attachmentMimetype.value = messageAttachmentMimetype
    attachmentFile.value = messageAttachmentFile
    attachmentName.value = messageAttachmentName
    attachmentSize.value = messageAttachmentSize
    
    // Restore the preview URL if we had an image
    if (messageAttachmentPath && messageAttachmentPreviewUrl) {
      attachmentPreviewUrl.value = messageAttachmentPreviewUrl;
    }
    
    // Remove temporary message on error
    if (messageListRef.value && messageListRef.value.removeTemporaryMessage) {
      messageListRef.value.removeTemporaryMessage()
    }
    
    const errorMessage = e?.response?.data?.message || e?.message || 'Failed to send message';
    const validationErrors = e?.response?.data?.errors;
    if (validationErrors) {
      console.error('Validation errors:', validationErrors);
      alert('Validation error: ' + JSON.stringify(validationErrors));
    } else {
      alert(errorMessage);
    }
  } finally {
    isSending.value = false
  }
}

function clearAttachmentState() {
  attachmentFile.value = null
  attachmentPath.value = null
  attachmentMimetype.value = null
  attachmentPreviewUrl.value = null
  attachmentName.value = null
  attachmentSize.value = null

  const imageInput = document.getElementById('image-upload-input') as HTMLInputElement | null
  if (imageInput) {
    imageInput.value = ''
  }

  const fileInput = document.getElementById('file-upload-input') as HTMLInputElement | null
  if (fileInput) {
    fileInput.value = ''
  }
}

function clearAttachment() {
  clearAttachmentState()
}

async function uploadAttachment(file: File) {
  console.log('Uploading attachment:', {
    name: file.name,
    type: file.type,
    size: file.size
  })

  isUploadingAttachment.value = true

  try {
    const response = await uploadFile(file)
    attachmentPath.value = response.data.path
    attachmentMimetype.value = response.data.mimetype || file.type || null
    attachmentName.value = response.data.original_name || file.name
    attachmentSize.value = response.data.size ?? file.size
    console.log('Attachment uploaded successfully:', {
      path: attachmentPath.value,
      mimetype: attachmentMimetype.value,
      name: attachmentName.value,
      size: attachmentSize.value
    })
  } catch (error) {
    console.error('Attachment upload error:', error)
    alert('File upload failed')
    clearAttachmentState()
  } finally {
    isUploadingAttachment.value = false
  }
}

async function onAttachmentChange(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (!files || !files[0]) {
    return
  }

  const file = files[0]
  attachmentFile.value = file
  attachmentPreviewUrl.value = file.type.startsWith('image/') ? URL.createObjectURL(file) : null

  await uploadAttachment(file)
}

function openMenu() {
  showMenu.value = !showMenu.value
}

function selectAddImage() {
  showMenu.value = false
  // Small delay to ensure the click event from the menu is handled first
  setTimeout(() => {
    document.getElementById('image-upload-input')?.click()
  }, 100)
}

function selectAddFile() {
  showMenu.value = false
  setTimeout(() => {
    document.getElementById('file-upload-input')?.click()
  }, 100)
}

const confirmDeleteChat = async (chat: any) => {
  if (!confirm(`Are you sure you want to delete the chat with "${chat.name}"? This action cannot be undone.`)) {
    return
  }
  
  try {
    await deleteChat(chat.id)
    
    // Remove the chat from the list
    chats.value = chats.value.filter(c => c.id !== chat.id)
    
    // If the deleted chat was selected, clear the selection
    if (selectedChat.value && selectedChat.value.id === chat.id) {
      selectedChat.value = null
      // Select the first available chat if any
      if (chats.value.length > 0) {
        selectedChat.value = chats.value[0]
      }
    }
  } catch (e: any) {
    console.error('Error deleting chat:', e)
    alert(e?.response?.data?.message || 'Failed to delete chat')
  }
}

const checkAuthAndRedirect = async () => {
  const authStore = useAuthStore()
  const router = useRouter()
  
  if (!authStore.isAuthenticated) {
    try {
      // Try to check auth status
      const valid = await authStore.checkAuth()
      if (!valid) {
        router.push('/login')
        return false
      }
      return true
    } catch (error) {
      router.push('/login')
      return false
    }
  }
  return true
}

onMounted(async () => {
  console.log('MessagesView component mounted')
  console.log('Auth store state:', useAuthStore())
  
  const isAuthenticated = await checkAuthAndRedirect()
  console.log('Authentication check result:', isAuthenticated)
  
  if (!isAuthenticated) {
    console.log('User not authenticated, returning early')
    return
  }
  
  loadingChats.value = true
  errorChats.value = null
  try {
    console.log('Fetching chats...')
    const response = await fetchChats()
    console.log('Chats response:', response)
    
    if (response && response.data && response.data.data) {
      chats.value = response.data.data
      console.log('Chats loaded:', chats.value)
      
      if (chats.value && chats.value.length > 0) {
        selectedChat.value = chats.value[0]
        console.log('Selected chat:', selectedChat.value)
      }
    } else {
      console.error('Invalid response format:', response)
      errorChats.value = 'Invalid response format from server'
    }
  } catch (e: any) {
    console.error('Error fetching chats:', e)
    
    if (e.response?.status === 401) {
      errorChats.value = 'Session expired. Please login again.'
      const authStore = useAuthStore()
      authStore.logout()
    } else {
      errorChats.value = e?.message || 'Failed to load chats.'
    }
  } finally {
    loadingChats.value = false
  }
})
</script>
