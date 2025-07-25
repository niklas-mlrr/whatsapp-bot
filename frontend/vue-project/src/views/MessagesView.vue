<template>
  <div class="h-screen bg-gray-100 flex flex-row overflow-hidden">
    <!-- Sidebar -->
    <aside class="w-80 bg-white border-r border-gray-200 flex flex-col h-full">
      <div class="p-4 font-bold text-lg border-b border-gray-200">Chats</div>
      <div class="flex-1 overflow-y-auto">
        <div v-if="loadingChats" class="text-blue-500 p-4">Loading chats...</div>
        <div v-if="errorChats" class="text-red-500 p-4">{{ errorChats }}</div>
        <ul v-if="!loadingChats && !errorChats">
          <li v-for="chat in chats" :key="chat" @click="selectChat(chat)"
              :class="['cursor-pointer px-4 py-3 border-b border-gray-100 hover:bg-green-50', chat === selectedChat ? 'bg-green-100 font-bold' : '']">
            {{ chat }}
          </li>
        </ul>
      </div>
    </aside>
    <!-- Main chat area -->
    <main class="flex-1 flex flex-col h-full overflow-hidden">
      <!-- Chat header -->
      <div class="flex items-center gap-3 px-6 py-4 bg-white border-b border-gray-200 shadow-sm min-h-[64px] sticky top-0 z-10">
        <div class="w-10 h-10 rounded-full bg-green-300 flex items-center justify-center text-green-700 font-bold text-lg">
          <span v-if="selectedChat">{{ selectedChat.slice(0,2).toUpperCase() }}</span>
        </div>
        <div class="flex flex-col">
          <span class="font-semibold text-lg text-gray-900">{{ selectedChat || 'Select a chat' }}</span>
          <span class="text-xs text-gray-400">Online</span>
        </div>
      </div>
      
      <!-- Messages area -->
      <div class="flex-1 overflow-y-auto">
        <MessageList ref="messageListRef" :chat="selectedChat" />
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
          :disabled="(!input && !imagePath) || !selectedChat || isSending"
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
import { ref, onMounted } from 'vue'
import MessageList from '../components/MessageList.vue'
import { fetchChats, sendMessage, uploadImage } from '../api/messages'

const chats = ref<string[]>([])
const loadingChats = ref(false)
const errorChats = ref<string | null>(null)
const selectedChat = ref<string | null>(null)
const input = ref('')
const imageFile = ref<File | null>(null)
const imagePath = ref<string | null>(null)
const imageMimetype = ref<string | null>(null)
const imagePreviewUrl = ref<string | null>(null)
const showMenu = ref(false)
const isSending = ref(false)

const messageListRef = ref<any>(null)

function selectChat(chat: string) {
  selectedChat.value = chat
}

async function sendMessageHandler() {
  if ((!input.value && !imagePath.value) || !selectedChat.value || isSending.value) return
  
  // Store the message content before clearing
  const messageContent = input.value
  const messageImagePath = imagePath.value
  const messageImageMimetype = imageMimetype.value
  
  isSending.value = true
  
  try {
    let payload: any = {
      sender: 'me',
      chat: selectedChat.value,
      type: messageImagePath ? 'image' : 'text',
      content: messageContent,
    }
    
    // If we have an image, include the media path and mimetype
    if (messageImagePath) {
      // If it's a data URL, we need to upload it first
      if (messageImagePath.startsWith('data:')) {
        try {
          // Convert data URL to blob
          const response = await fetch(messageImagePath);
          const blob = await response.blob();
          const file = new File([blob], 'image.jpg', { type: messageImageMimetype || 'image/jpeg' });
          
          // Upload the image
          const uploadResponse = await uploadImage(file);
          payload.media = uploadResponse.data.path;
          payload.mimetype = messageImageMimetype || 'image/jpeg';
        } catch (e) {
          console.error('Error uploading image:', e);
          throw new Error('Failed to upload image');
        }
      } else {
        // It's already a path, just use it directly
        payload.media = messageImagePath;
        payload.mimetype = messageImageMimetype;
      }
    }
    
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
        chat: selectedChat.value,
        type: messageImagePath ? 'image' : 'text',
        content: messageContent,
        media: messageImagePath,
        mimetype: messageImageMimetype,
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
  } catch (e) {
    console.error('Error sending message:', e);
    
    // Restore the input values on error
    input.value = messageContent
    imagePath.value = messageImagePath
    imageMimetype.value = messageImageMimetype
    
    // Recreate the preview URL if we had an image
    if (messageImagePath && messageImagePath.startsWith('data:')) {
      imagePreviewUrl.value = messageImagePath;
    }
    
    // Remove temporary message on error
    if (messageListRef.value && messageListRef.value.removeTemporaryMessage) {
      messageListRef.value.removeTemporaryMessage()
    }
    
    alert(e instanceof Error ? e.message : 'Failed to send message')
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
    
    // Create preview URL
    imagePreviewUrl.value = URL.createObjectURL(file)
    
    // Upload the image immediately
    try {
      const res = await uploadImage(file)
      imagePath.value = res.data.path
      imageMimetype.value = file.type
    } catch (err) {
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

onMounted(async () => {
  loadingChats.value = true
  errorChats.value = null
  try {
    const response = await fetchChats()
    chats.value = response.data.data
    if (chats.value.length > 0) {
      selectedChat.value = chats.value[0]
    }
  } catch (e: any) {
    errorChats.value = e?.message || 'Failed to load chats.'
  } finally {
    loadingChats.value = false
  }
})
</script>
