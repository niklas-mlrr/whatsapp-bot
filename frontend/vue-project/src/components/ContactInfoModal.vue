<template>
  <TransitionRoot as="template" :show="isOpen">
    <Dialog as="div" class="relative z-50" @close="closeModal">
      <TransitionChild 
        as="template" 
        enter="ease-out duration-300"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="ease-in duration-200"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" />
      </TransitionChild>

      <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
          <TransitionChild
            as="template"
            enter="ease-out duration-300"
            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            enter-to="opacity-100 translate-y-0 sm:scale-100"
            leave="ease-in duration-200"
            leave-from="opacity-100 translate-y-0 sm:scale-100"
            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
          >
            <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white dark:bg-zinc-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
              <div class="absolute right-0 top-0 pr-4 pt-4">
                <button
                  type="button"
                  class="rounded-md bg-white dark:bg-zinc-700 text-gray-400 hover:text-gray-500 focus:outline-none"
                  @click="closeModal"
                >
                  <span class="sr-only">Close</span>
                  <XMarkIcon class="h-6 w-6" aria-hidden="true" />
                </button>
              </div>
              <div class="sm:flex sm:items-start">
                <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                  <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900 dark:text-white mb-4">
                    {{ chatName }}
                  </DialogTitle>
                  <div class="mt-2">
                    <!-- Profile Picture -->
                    <div class="flex flex-col items-center mb-6">
                      <div class="relative">
                        <img 
                          v-if="profilePictureUrl" 
                          :src="profilePictureUrl" 
                          :alt="chatName"
                          class="h-32 w-32 rounded-full object-cover"
                        />
                        <div 
                          v-else
                          class="h-32 w-32 rounded-full bg-green-300 dark:bg-green-700 flex items-center justify-center text-4xl font-bold text-green-700 dark:text-green-200"
                        >
                          {{ chatName.charAt(0).toUpperCase() }}
                        </div>
                      </div>
                    </div>

                    <!-- Description/Bio -->
                    <div class="mt-4">
                      <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        {{ isGroup ? 'Gruppenbeschreibung' : 'Über' }}
                      </h4>
                      <p v-if="description" class="text-gray-600 dark:text-gray-300 text-sm whitespace-pre-line">
                        {{ description }}
                      </p>
                      <p v-else class="text-gray-500 dark:text-gray-400 text-sm italic">
                        Keine Beschreibung vorhanden
                      </p>
                    </div>

                    <!-- Additional Info -->
                    <div v-if="!isGroup" class="mt-6">
                      <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Telefonnummer
                      </h4>
                      <p class="text-gray-600 dark:text-gray-300 text-sm">
                        {{ phoneNumber || 'Nicht verfügbar' }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true
  },
  chat: {
    type: Object as () => any,
    required: true
  }
});

const emit = defineEmits(['close']);

const closeModal = () => {
  emit('close');
};

// Computed properties for the chat info
const chatName = computed(() => props.chat?.name || 'Unbekannter Kontakt');
const isGroup = computed(() => props.chat?.is_group || false);

const phoneNumber = computed(() => {
  const chat: any = props.chat;
  if (!chat) return null;
  const extract = (val?: string | null) => {
    if (!val || typeof val !== 'string') return null;
    const raw = val.replace(/@.*$/, '');
    if (/^\d+$/.test(raw)) return '+' + raw;
    return raw || null;
  };

  if (chat.metadata?.whatsapp_id) {
    const out = extract(chat.metadata.whatsapp_id);
    if (out) return out;
  }

  if (chat.original_name) {
    const out = extract(chat.original_name);
    if (out) return out;
  }

  if (Array.isArray(chat.participants)) {
    const candidate = chat.participants.find((p: any) => p && String(p) !== 'me');
    const out = extract(candidate ? String(candidate) : '');
    if (out) return out;
  }

  if (typeof chat.name === 'string') {
    const phoneRegex = /^[+\d\s-]+$/;
    if (phoneRegex.test(chat.name)) return chat.name;
  }

  return null;
});

// Get profile picture URL
const profilePictureUrl = computed(() => {
  if (props.chat?.contact_info?.profile_picture_url) {
    return props.chat.contact_info.profile_picture_url;
  }
  return null;
});

// Get description/bio
const description = computed(() => {
  if (isGroup.value) {
    return props.chat?.contact_info?.description || null;
  } else {
    // Fallback to description if bio is not available
    return props.chat?.contact_info?.bio || props.chat?.contact_info?.description || null;
  }
});
</script>
