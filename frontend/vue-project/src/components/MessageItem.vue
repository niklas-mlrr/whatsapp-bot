<template>
  <div 
    class="flex w-full mb-1 group" 
    :class="[bubbleAlign, { 'opacity-60': message.isSending }]"
    :data-message-id="message.id"
  >
    <!-- Sender avatar (left side for received messages) -->
    <div 
      v-if="!isMe" 
      class="flex-shrink-0 w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 font-bold text-sm mr-2 shadow-sm"
      :title="message.sender"
    >
      {{ senderInitials }}
    </div>

    <!-- Message content -->
    <div class="flex flex-col max-w-[80%]" :class="{ 'items-end': isMe, 'items-start': !isMe }">
      <!-- Sender name (for group chats) -->
      <div v-if="showSenderName" class="text-xs text-gray-500 mb-0.5 px-2">
        {{ message.sender }}
      </div>
      
      <!-- Message bubble -->
      <div 
        :class="bubbleClass"
        class="relative"
      >
        <!-- Message content based on type -->
        <template v-if="message.type === 'text' || !message.type">
          <span class="whitespace-pre-wrap break-words">
            {{ message.content }}
          </span>
        </template>
        
        <!-- Image message -->
        <template v-else-if="message.type === 'image' || message.mimetype?.startsWith('image/')">
          <div class="relative group">
            <img 
              :src="imageSrc" 
              :alt="message.content || 'Image'" 
              class="max-h-64 max-w-full rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
              @click="openMediaViewer"
              @load="handleImageLoad"
              @error="handleImageError"
            />
            <div v-if="isImageLoading" class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-30 rounded-lg">
              <div class="animate-pulse text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
              </div>
            </div>
          </div>
          <span v-if="message.content" class="block mt-2 whitespace-pre-line">{{ message.content }}</span>
        </template>
        
        <!-- Document message -->
        <template v-else-if="message.type === 'document' || (message.mimetype && !message.mimetype.startsWith('image/') && !message.mimetype.startsWith('audio/') && !message.mimetype.startsWith('video/'))">
          <div>
            <a 
              :href="documentUrl" 
              target="_blank" 
              class="flex items-center p-2 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
            >
              <div class="p-2 bg-gray-200 rounded-lg mr-3">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">
                  {{ message.filename || 'Attachment' }}
                </p>
                <p class="text-xs text-gray-500">
                  <span v-if="message.size">{{ formatFileSize(message.size) }}</span>
                  <span v-if="message.size && message.mimetype"> • </span>
                  <span>{{ message.mimetype || 'File' }}</span>
                </p>
              </div>
              <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
              </svg>
            </a>
            <span v-if="message.content" class="block mt-2 whitespace-pre-line">{{ message.content }}</span>
          </div>
        </template>
        
        <!-- Audio message -->
        <template v-else-if="message.type === 'audio' || message.mimetype?.startsWith('audio/')">
          <div>
            <div class="flex items-center p-2 bg-gray-100 rounded-lg">
              <button 
                @click="toggleAudioPlayback"
                class="p-2 bg-gray-200 rounded-full mr-3 focus:outline-none hover:bg-gray-300 transition-colors"
              >
                <svg v-if="!isPlayingAudio" class="w-6 h-6 text-gray-700" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                </svg>
                <svg v-else class="w-6 h-6 text-gray-700" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
              </button>
              <div class="flex-1">
                <div class="w-full bg-gray-200 rounded-full h-1.5 mb-1">
                  <div class="bg-blue-600 h-1.5 rounded-full" :style="{ width: audioProgress + '%' }"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-500">
                  <span>{{ formatAudioTime(currentAudioTime) }}</span>
                  <span>{{ formatAudioTime(audioDuration) }}</span>
                </div>
              </div>
            </div>
            <audio 
              ref="audioPlayer" 
              :src="mediaUrl" 
              @timeupdate="updateAudioProgress"
              @loadedmetadata="setAudioDuration"
              @ended="onAudioEnded"
            ></audio>
            <span v-if="message.content" class="block mt-2 whitespace-pre-line">{{ message.content }}</span>
          </div>
        </template>
        
        <!-- Video message -->
        <template v-else-if="message.type === 'video' || message.mimetype?.startsWith('video/')">
          <div class="relative">
            <video 
              :src="mediaUrl" 
              :poster="message.thumbnail || ''"
              class="max-h-64 max-w-full rounded-lg cursor-pointer"
              controls
              @click="toggleVideoPlayback"
            ></video>
            <button 
              v-if="!isVideoPlaying"
              @click="toggleVideoPlayback"
              class="absolute inset-0 flex items-center justify-center w-full h-full text-white bg-black bg-opacity-30 rounded-lg focus:outline-none"
            >
              <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
              </svg>
            </button>
          </div>
          <span v-if="message.content" class="block mt-2 whitespace-pre-line">{{ message.content }}</span>
        </template>
        
        <!-- Location message -->
        <template v-else-if="message.type === 'location' && message.location">
          <a 
            :href="`https://www.google.com/maps?q=${message.location.latitude},${message.location.longitude}`" 
            target="_blank"
            class="block overflow-hidden rounded-lg border border-gray-200"
          >
            <div class="h-32 bg-gray-100 relative">
              <!-- Static map thumbnail (you can replace with actual map component) -->
              <div class="absolute inset-0 flex items-center justify-center text-gray-400">
                <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                  <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                </svg>
              </div>
              <div class="absolute bottom-2 left-2 bg-white bg-opacity-90 px-2 py-1 rounded text-xs font-medium">
                View on Map
              </div>
            </div>
            <div class="p-3">
              <p class="font-medium text-gray-900">Location</p>
              <p class="text-sm text-gray-500 truncate">{{ message.location.name || 'Shared location' }}</p>
            </div>
          </a>
        </template>
        
        <!-- Contact message -->
        <template v-else-if="message.type === 'contact' && message.contact">
          <div class="border rounded-lg overflow-hidden">
            <div class="bg-gray-50 p-3 border-b">
              <p class="font-medium text-gray-900">Contact</p>
            </div>
            <div class="p-3">
              <div class="flex items-center">
                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 mr-3">
                  <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6z"></path>
                  </svg>
                </div>
                <div>
                  <p class="font-medium text-gray-900">{{ message.contact.name || 'Contact' }}</p>
                  <p v-if="message.contact.phone" class="text-sm text-gray-500">{{ message.contact.phone }}</p>
                </div>
              </div>
              <div v-if="message.contact.email" class="mt-3 pt-3 border-t">
                <p class="text-xs text-gray-500 mb-1">Email</p>
                <a :href="`mailto:${message.contact.email}`" class="text-sm text-blue-600 hover:underline">
                  {{ message.contact.email }}
                </a>
              </div>
            </div>
          </div>
        </template>
        
        <!-- Unsupported message type -->
        <template v-else>
          <div class="flex items-center p-3 bg-gray-50 rounded-lg">
            <svg class="w-5 h-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
            <span class="text-sm text-gray-600">Unsupported message type: {{ message.type || 'unknown' }}</span>
          </div>
        </template>
        
        <!-- Message status and time -->
        <div class="flex items-center justify-end gap-1.5 mt-1">
          <!-- Message status icons -->
          <span v-if="isMe" class="text-xs">
            <template v-if="message.isSending">
              <svg class="w-3 h-3 text-gray-400 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
            </template>
            <template v-else-if="message.isFailed">
              <svg class="w-3 h-3 text-red-500 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
            </template>
            <template v-else-if="message.isDelivered">
              <svg class="w-3 h-3 text-gray-400 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
            </template>
            <template v-else-if="message.isRead">
              <svg class="w-3 h-3 text-blue-500 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
            </template>
          </span>
          
          <!-- Message time -->
          <span class="text-xs text-gray-400">
            {{ formattedTime }}
          </span>
          
          <!-- Sending indicator -->
          <span v-if="message.isSending" class="text-xs text-gray-400 italic">
            sending...
          </span>
        </div>
      </div>
      
      <!-- Message reactions (future feature) -->
      <!-- <div v-if="message.reactions?.length" class="flex flex-wrap gap-1 mt-1">
        <span 
          v-for="(reaction, index) in message.reactions" 
          :key="index"
          class="text-xs bg-gray-100 rounded-full px-2 py-0.5"
        >
          {{ reaction.emoji }} {{ reaction.count > 1 ? reaction.count : '' }}
        </span>
      </div> -->
    </div>
    
    <!-- Sender avatar (right side for sent messages) -->
    <div 
      v-if="isMe" 
      class="flex-shrink-0 w-8 h-8 rounded-full bg-green-100 flex items-center justify-center text-green-700 font-bold text-sm ml-2 shadow-sm"
      :title="message.sender || 'You'"
    >
      {{ senderInitials }}
    </div>
    
    <!-- Message context menu (future feature) -->
    <!-- <div class="absolute right-0 top-0 opacity-0 group-hover:opacity-100 transition-opacity">
      <button class="p-1 text-gray-400 hover:text-gray-600">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"></path>
        </svg>
      </button>
    </div> -->
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, defineComponent } from 'vue'

const props = defineProps<{ 
  message: {
    id: string | number
    type?: string
    content?: string
    sender: string
    isMe?: boolean
    isSending?: boolean
    isFailed?: boolean
    isDelivered?: boolean
    isRead?: boolean
    sending_time?: string
    created_at?: string
    updated_at?: string
    media?: string | {
      url?: string | null
      path?: string | null
      thumbnail_url?: string | null
      [key: string]: any
    } | null
    mimetype?: string | null
    filename?: string
    size?: number
    thumbnail?: string
    location?: {
      latitude: number
      longitude: number
      name?: string
      address?: string
    }
    contact?: {
      name?: string
      phone?: string
      email?: string
    }
    [key: string]: any
  } 
}>()

// Debug logging for document messages
if (props.message.type === 'document') {
  console.log('MessageItem - Document message:', {
    id: props.message.id,
    filename: props.message.filename,
    size: props.message.size,
    content: props.message.content,
    mimetype: props.message.mimetype,
    fullMessage: props.message
  })
}

const emit = defineEmits<{
  'open-image-preview': [payload: { src: string; caption?: string }]
}>()

// Refs
const audioPlayer = ref<HTMLAudioElement | null>(null)
const isPlayingAudio = ref(false)
const audioProgress = ref(0)
const currentAudioTime = ref(0)
const audioDuration = ref(0)
const isVideoPlaying = ref(false)
const isImageLoading = ref(true)

// Computed properties
const isMe = computed(() => props.message.sender === 'me' || props.message.isMe)
const showSenderName = computed(() => !isMe.value && props.message.sender)

const senderInitials = computed(() => {
  const sender = props.message.sender || '?'
  return sender
    .toString()
    .split(' ')
    .map((n: string) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
})

const formattedTime = computed(() => {
  const timeStr = props.message.sending_time || props.message.created_at
  if (!timeStr) return ''
  
  const d = new Date(timeStr)
  // Always show the send time (HH:MM) for every message, including past days
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })
})

const mediaUrl = computed(() => {
  if (!props.message.media) return '';

  if (typeof props.message.media === 'object' && props.message.media.url) {
    return props.message.media.url;
  }
  
  if (typeof props.message.media === 'string') {
    // If it's already a full URL, return as is
    if (/^https?:\/\//.test(props.message.media)) {
      return props.message.media;
    }
    // Otherwise, assume it's a path that needs the storage prefix
    return `/storage/${props.message.media}`;
  }

  return '';
});

const imageSrc = computed(() => {
  const media = props.message.media;
  
  // Handle object media
  if (media && typeof media === 'object') {
    // Use thumbnail if available
    if (media.thumbnail_url) {
      return media.thumbnail_url;
    }

    // Use media path when explicit url is missing
    if (media.path) {
      const path = media.path;
      if (path) {
        return /^https?:\/\//.test(path) ? path : `/storage/${path}`;
      }
    }

    // Fallback to full image URL from media object
    if (media.url) {
      return media.url;
    }
  }

  // Fallback for older message structures or if media object is just a string path
  if (typeof props.message.media === 'string') {
    if (/^https?:\/\//.test(props.message.media)) {
      return props.message.media;
    }
    return `/storage/${props.message.media}`;
  }

  // Check direct media_url field
  if (typeof props.message.media_url === 'string') {
    if (/^https?:\/\//.test(props.message.media_url)) {
      return props.message.media_url;
    }
    return `/storage/${props.message.media_url}`;
  }

  // Check metadata for media_path
  const metadataMediaPath = (() => {
    const metadata = props.message.metadata;
    if (!metadata) return null;
    if (typeof metadata === 'string') {
      try {
        const parsed = JSON.parse(metadata);
        return parsed?.media_path ?? null;
      } catch (error) {
        console.error('Failed to parse metadata JSON:', error);
        return null;
      }
    }
    return metadata.media_path ?? null;
  })();

  if (typeof metadataMediaPath === 'string' && metadataMediaPath.length > 0) {
    return /^https?:\/\//.test(metadataMediaPath)
      ? metadataMediaPath
      : `/storage/${metadataMediaPath}`;
  }
  
  return '';
});

const documentUrl = computed(() => {
  if (!props.message.media && !props.message.content) return '#'
  return mediaUrl.value
})

const bubbleAlign = computed(() => 
  isMe.value ? 'justify-end' : 'justify-start'
)

const bubbleClass = computed(() => {
  const baseClasses = [
    'px-4 py-2 rounded-lg shadow text-sm break-words',
    'relative transition-all duration-200',
    'max-w-full',
    isMe.value 
      ? 'bg-green-100 text-green-900 self-end rounded-tr-none' 
      : 'bg-white text-gray-900 self-start rounded-tl-none border border-gray-200'
  ]
  
  // Add additional classes based on message state
  if (props.message.isSending) {
    baseClasses.push('opacity-75')
  }
  
  if (props.message.isFailed) {
    baseClasses.push('border-red-200 bg-red-50')
  }
  
  return baseClasses.join(' ')
})

// Methods
function formatFileSize(bytes: number = 0) {
  if (bytes === 0) return '0 Bytes'
  
  const k = 1024
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

function formatAudioTime(seconds: number) {
  if (isNaN(seconds)) return '0:00'
  
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = Math.floor(seconds % 60)
  return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`
}

// Audio player methods
function toggleAudioPlayback() {
  if (!audioPlayer.value) return
  
  if (isPlayingAudio.value) {
    audioPlayer.value.pause()
  } else {
    audioPlayer.value.play()
      .then(() => {
        isPlayingAudio.value = true
      })
      .catch(error => {
        console.error('Error playing audio:', error)
      })
  }
}

function updateAudioProgress() {
  if (!audioPlayer.value) return
  
  const { currentTime, duration } = audioPlayer.value
  currentAudioTime.value = currentTime
  
  if (duration > 0) {
    audioProgress.value = (currentTime / duration) * 100
  }
}

function setAudioDuration() {
  if (!audioPlayer.value) return
  audioDuration.value = audioPlayer.value.duration || 0
}

function onAudioEnded() {
  isPlayingAudio.value = false
  audioProgress.value = 0
  currentAudioTime.value = 0
  
  if (audioPlayer.value) {
    audioPlayer.value.currentTime = 0
  }
}

// Video player methods
function toggleVideoPlayback(event: Event) {
  const video = event.target as HTMLVideoElement
  
  if (video.paused) {
    video.play()
    isVideoPlaying.value = true
  } else {
    video.pause()
    isVideoPlaying.value = false
  }
}

// Image loading handlers
function handleImageLoad() {
  isImageLoading.value = false
}

function handleImageError() {
  isImageLoading.value = false
  console.error('Failed to load image:', imageSrc.value)
}

function openMediaViewer(event: Event) {
  emit('open-image-preview', {
    src: imageSrc.value,
    caption: props.message.content
  })
}

// Lifecycle
onMounted(() => {
  // Initialize audio duration if available
  if (audioPlayer.value && audioPlayer.value.readyState > 0) {
    setAudioDuration()
  }
})

onUnmounted(() => {
  // Clean up audio player
  if (audioPlayer.value) {
    audioPlayer.value.pause()
    audioPlayer.value = null
  }
})
// Define the component options
defineOptions({
  name: 'MessageItem',
  inheritAttrs: false
})
</script>

<style scoped>
/* Message bubble animations */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.message-enter-active {
  animation: fadeIn 0.2s ease-out;
}

/* Message bubble styling */
.message-bubble {
  position: relative;
  transition: all 0.2s ease;
  word-wrap: break-word;
  overflow-wrap: break-word;
  hyphens: auto;
}

.message-bubble:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

/* Sent message bubble */
.message-bubble.sent {
  background-color: #dcf8c6;
  border-top-right-radius: 4px;
  margin-left: 20%;
}

/* Received message bubble */
.message-bubble.received {
  background-color: #ffffff;
  border-top-left-radius: 4px;
  margin-right: 20%;
  border: 1px solid #e5e5ea;
}

/* Message status indicators */
.message-status {
  display: inline-flex;
  align-items: center;
  margin-left: 4px;
  vertical-align: middle;
}

/* Audio player styles */
.audio-player {
  width: 100%;
  min-width: 200px;
  max-width: 300px;
}

.audio-progress {
  height: 4px;
  background-color: #e0e0e0;
  border-radius: 2px;
  overflow: hidden;
  margin: 6px 0;
}

.audio-progress-bar {
  height: 100%;
  background-color: #4caf50;
  transition: width 0.1s linear;
}

.audio-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}

.audio-time {
  font-size: 0.75rem;
  color: #666;
  min-width: 40px;
  text-align: center;
}

/* Image message styles */
.image-message {
  position: relative;
  display: inline-block;
  max-width: 100%;
  border-radius: 8px;
  overflow: hidden;
  background-color: #f5f5f5;
}

.image-message img {
  display: block;
  max-width: 100%;
  height: auto;
  transition: opacity 0.2s ease;
}

/* Document message styles */
.document-message {
  display: flex;
  align-items: center;
  padding: 12px;
  background-color: #f8f9fa;
  border-radius: 8px;
  border: 1px solid #e9ecef;
  text-decoration: none;
  color: inherit;
  transition: background-color 0.2s ease;
}

.document-message:hover {
  background-color: #e9ecef;
  text-decoration: none;
}

.document-icon {
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #e9ecef;
  border-radius: 6px;
  margin-right: 12px;
  color: #495057;
}

.document-info {
  flex: 1;
  min-width: 0;
}

.document-name {
  font-weight: 500;
  font-size: 0.875rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 2px;
}

.document-meta {
  font-size: 0.75rem;
  color: #6c757d;
  display: flex;
  align-items: center;
}

/* Message time styling */
.message-time {
  font-size: 0.6875rem;
  color: #999999;
  margin-top: 2px;
  text-align: right;
  white-space: nowrap;
}

/* Sender name in group chats */
.sender-name {
  font-weight: 600;
  font-size: 0.75rem;
  color: #666;
  margin-bottom: 2px;
  padding: 0 8px;
}

/* Loading indicator for images */
.image-loading {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.1);
  border-radius: 8px;
}

/* Responsive adjustments */
@media (max-width: 640px) {
  .message-bubble.sent {
    margin-left: 10%;
  }
  
  .message-bubble.received {
    margin-right: 10%;
  }
  
  .document-message {
    padding: 8px;
  }
  
  .document-icon {
    width: 36px;
    height: 36px;
    margin-right: 8px;
  }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
  .message-bubble.sent {
    background-color: #005c4b;
    color: #e9edef;
  }
  
  .message-bubble.received {
    background-color: #202c33;
    color: #e9edef;
    border-color: #2a3942;
  }
  
  .document-message {
    background-color: #2a3942;
    border-color: #374045;
    color: #e9edef;
  }
  
  .document-message:hover {
    background-color: #374045;
  }
  
  .document-icon {
    background-color: #374045;
    color: #8696a0;
  }
  
  .message-time {
    color: #8696a0;
  }
  
  .sender-name {
    color: #8696a0;
  }
}
</style>