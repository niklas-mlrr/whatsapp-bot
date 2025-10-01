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
        <!-- Image preview -->
        <div v-if="imagePreviewUrl" class="relative bg-gray-50 p-2 border-b border-gray-200">
          <div class="max-w-[200px] relative">
            <img :src="imagePreviewUrl" alt="Preview" class="max-h-32 rounded-lg" />
            <button 
              type="button" 
              @click="clearImage"
              class="absolute -right-2 -top-2 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center hover:bg-red-600 transition-colors"
              title="Remove image"
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
          <div v-if="showMenu" class="absolute left-0 bottom-12 z-10 bg-white border border-gray-200 rounded shadow-lg min-w-[140px]">
            <ul>
              <li @click="selectAddImage" class="px-4 py-2 hover:bg-green-50 cursor-pointer">Add image</li>
              <!-- Future: <li class='px-4 py-2 hover:bg-green-50 cursor-pointer'>Create poll</li> -->
            </ul>
          </div>
        </div>
        <input id="image-upload-input" type="file" accept="image/*" class="hidden" @change="onImageChange" :disabled="!selectedChat" />
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
import { fetchChats, sendMessage, uploadImage, deleteChat } from '../api/messages'

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
const imageFile = ref<File | null>(null)
const imagePath = ref<string | null>(null)
const imageMimetype = ref<string | null>(null)
const imagePreviewUrl = ref<string | null>(null)
const showMenu = ref(false)
const isSending = ref(false)

const messageListRef = ref<any>(null)

// Watch for changes to input field to debug if image is being cleared
watch(input, (newVal: string, oldVal: string) => {
  console.log('Input changed:', { newVal, oldVal, imagePath: imagePath.value, imagePreviewUrl: imagePreviewUrl.value });
});

// Computed property to determine if we can send a message
const canSend = computed(() => {
  const hasText = !!(input.value && input.value.trim().length > 0)
  const hasImage = !!imagePath.value
  const hasChat = !!selectedChat.value
  const notSending = !isSending.value
  
  const result = (hasText || hasImage) && hasChat && notSending
  console.log('canSend check:', { hasText, hasImage, hasChat, notSending, result, inputValue: input.value, imagePathValue: imagePath.value })
  
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
  if ((!input.value && !imagePath.value) || !selectedChat.value || isSending.value) return
  
  console.log('=== START sendMessageHandler ===');
  console.log('Current state before storing:', {
    inputValue: input.value,
    imagePathValue: imagePath.value,
    imageMimetypeValue: imageMimetype.value
  });
  
  // Store the message content before clearing
  const messageContent = input.value
  const messageImagePath = imagePath.value
  const messageImageMimetype = imageMimetype.value
  const messageImageFile = imageFile.value
  const messageImagePreviewUrl = imagePreviewUrl.value
  
  console.log('Stored values:', {
    messageContent,
    messageImagePath,
    messageImageMimetype
  });
  
  isSending.value = true
  
  try {
    let payload: any = {
      sender: 'me',
      chat: selectedChat.value.name,
      type: messageImagePath ? 'image' : 'text',
      content: messageContent || '',  // Ensure content is at least an empty string
    }
    
    console.log('Payload before adding media:', payload);
    
    // If we have an image, include the media path and mimetype
    // The image was already uploaded in onImageChange, so we just use the path
    if (messageImagePath) {
      payload.media = messageImagePath;
      payload.mimetype = messageImageMimetype || 'image/jpeg';
      console.log('Added media to payload:', { media: payload.media, mimetype: payload.mimetype });
    }
    
    console.log('Final payload to send:', payload);
    
    // Clear input immediately to show responsiveness
    input.value = ''
    imageFile.value = null
    imagePath.value = null
    imageMimetype.value = null
    imagePreviewUrl.value = null
    
    // Add temporary "sending" message to the chat
    if (messageListRef.value && messageListRef.value.addTemporaryMessage) {
      messageListRef.value.addTemporaryMessage({
        id: 'temp-' + Date.now(),
        sender: 'me',
        chat: selectedChat.value.id,
        type: messageImagePath ? 'image' : 'text',
        content: messageContent || '',  // Empty string if no caption
        media: messageImagePath || undefined,
        mimetype: messageImageMimetype || undefined,
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
    imagePath.value = messageImagePath
    imageMimetype.value = messageImageMimetype
    imageFile.value = messageImageFile
    
    // Restore the preview URL if we had an image
    if (messageImagePath && messageImagePreviewUrl) {
      imagePreviewUrl.value = messageImagePreviewUrl;
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

function clearImage() {
  imageFile.value = null
  imagePath.value = null
  imageMimetype.value = null
  imagePreviewUrl.value = null
  
  // Reset the file input
  const fileInput = document.getElementById('image-upload-input') as HTMLInputElement
  if (fileInput) {
    fileInput.value = ''
  }
}

async function onImageChange(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files && files[0]) {
    const file = files[0]
    imageFile.value = file
    
    console.log('Image selected:', file.name, file.type);
    
    // Create preview URL
    imagePreviewUrl.value = URL.createObjectURL(file)
    
    // Upload the image immediately
    try {
      const res = await uploadImage(file)
      imagePath.value = res.data.path
      imageMimetype.value = file.type
      console.log('Image uploaded successfully:', {
        path: imagePath.value,
        mimetype: imageMimetype.value,
        url: res.data.url
      });
    } catch (err) {
      console.error('Image upload error:', err);
      alert('Image upload failed')
      clearImage()
    }
  }
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
