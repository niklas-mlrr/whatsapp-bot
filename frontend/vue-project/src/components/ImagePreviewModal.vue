<template>
  <!-- Backdrop overlay with blur effect -->
  <Teleport to="body">
    <Transition name="fade">
      <div 
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center"
        @click="close"
      >
        <!-- Blurred backdrop -->
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
        
        <!-- Image container -->
        <div 
          class="relative z-10 flex items-center justify-center w-[95vw] md:w-[85vw] h-[90vh] md:h-[85vh]"
          @click.stop
        >
          <!-- Close button -->
          <button
            @click="close"
            class="absolute top-2 right-2 md:top-4 md:right-4 z-20 bg-white/10 hover:bg-white/20 text-white rounded-full p-2 md:p-3 transition-all duration-200 backdrop-blur-md"
            title="Close (Esc)"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          
          <!-- Navigation buttons -->
          <button
            v-if="hasPrevious"
            @click.stop="previous"
            class="absolute left-2 md:left-4 z-20 bg-white/10 hover:bg-white/20 text-white rounded-full p-2 md:p-3 transition-all duration-200 backdrop-blur-md"
            title="Previous image"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          
          <button
            v-if="hasNext"
            @click.stop="next"
            class="absolute right-2 md:right-4 z-20 bg-white/10 hover:bg-white/20 text-white rounded-full p-2 md:p-3 transition-all duration-200 backdrop-blur-md"
            title="Next image"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>
          
          <!-- Image with caption -->
          <div class="flex flex-col items-center justify-center max-w-full max-h-full">
            <!-- Loading spinner -->
            <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center">
              <div class="animate-spin rounded-full h-12 w-12 border-4 border-white border-t-transparent"></div>
            </div>
            
            <!-- Image -->
            <img
              v-show="!isLoading"
              :src="imageSrc"
              :alt="caption || 'Image preview'"
              class="max-w-full max-h-[calc(90vh-4rem)] md:max-h-[calc(85vh-4rem)] object-contain rounded-lg shadow-2xl"
              @load="handleImageLoad"
              @error="handleImageError"
            />
            
            <!-- Caption -->
            <div 
              v-if="caption && !isLoading"
              class="mt-2 md:mt-4 px-4 md:px-6 py-2 md:py-3 bg-white/10 backdrop-blur-md rounded-lg text-white text-center max-w-2xl text-sm md:text-base"
            >
              {{ caption }}
            </div>
          </div>
          
          <!-- Download button -->
          <a
            v-if="!isLoading"
            :href="imageSrc"
            :download="downloadFilename"
            class="absolute bottom-2 right-2 md:bottom-4 md:right-4 z-20 bg-white/10 hover:bg-white/20 text-white rounded-full p-2 md:p-3 transition-all duration-200 backdrop-blur-md"
            title="Download image"
            @click.stop
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
          </a>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

interface Props {
  isOpen: boolean
  imageSrc: string
  caption?: string
  images?: string[]
  currentIndex?: number
}

const props = withDefaults(defineProps<Props>(), {
  isOpen: false,
  imageSrc: '',
  caption: '',
  images: () => [],
  currentIndex: 0
})

const emit = defineEmits<{
  close: []
  updateIndex: [index: number]
}>()

const isLoading = ref(true)

const downloadFilename = computed(() => {
  const url = props.imageSrc
  const filename = url.split('/').pop() || 'image'
  return filename
})

const hasPrevious = computed(() => {
  return props.images.length > 1 && props.currentIndex > 0
})

const hasNext = computed(() => {
  return props.images.length > 1 && props.currentIndex < props.images.length - 1
})

const close = () => {
  emit('close')
}

const previous = () => {
  if (hasPrevious.value) {
    emit('updateIndex', props.currentIndex - 1)
  }
}

const next = () => {
  if (hasNext.value) {
    emit('updateIndex', props.currentIndex + 1)
  }
}

const handleImageLoad = () => {
  isLoading.value = false
}

const handleImageError = () => {
  isLoading.value = false
  console.error('Failed to load image:', props.imageSrc)
}

// Reset loading state when image changes
watch(() => props.imageSrc, () => {
  isLoading.value = true
})

// Keyboard navigation
const handleKeydown = (event: KeyboardEvent) => {
  if (!props.isOpen) return
  
  switch (event.key) {
    case 'Escape':
      close()
      break
    case 'ArrowLeft':
      previous()
      break
    case 'ArrowRight':
      next()
      break
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})

// Prevent body scroll when modal is open
watch(() => props.isOpen, (isOpen) => {
  if (isOpen) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})
</script>

<style scoped>
/* Fade transition */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Smooth image transitions */
img {
  transition: opacity 0.2s ease;
}
</style>
