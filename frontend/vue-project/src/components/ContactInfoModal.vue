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
                    <div v-if="!isGroupView" class="mt-6">
                      <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Telefonnummer
                      </h4>
                      <p class="text-gray-600 dark:text-gray-300 text-sm">
                        {{ phoneNumber || 'Nicht verfügbar' }}
                      </p>
                    </div>

                    <div v-if="isGroupView" class="mt-6">
                      <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Teilnehmer</h4>
                        <div class="flex items-center gap-2">
                          <button v-if="viewingParticipant" @click="openAddContactModal" class="text-xs px-3 py-1.5 rounded bg-green-500 hover:bg-green-600 text-white font-medium flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Kontakt hinzufügen / bearbeiten
                          </button>
                          <button v-if="viewingParticipant" @click="clearParticipantView" class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-zinc-700 text-gray-700 dark:text-gray-200">Zurück zur Gruppe</button>
                        </div>
                      </div>
                      <div v-if="!viewingParticipant" class="space-y-2 max-h-72 overflow-y-auto pr-1">
                        <div v-for="p in participantsList" :key="p.jid" class="flex items-center justify-between p-2 rounded hover:bg-green-50 dark:hover:bg-zinc-700">
                          <div class="flex items-center gap-3 min-w-0">
                            <img 
                              v-if="p.profilePictureUrl" 
                              :src="p.profilePictureUrl" 
                              :alt="p.display"
                              class="w-9 h-9 rounded-full object-cover"
                            />
                            <div v-else class="w-9 h-9 rounded-full bg-green-300 dark:bg-green-800 flex items-center justify-center text-green-700 dark:text-green-200 font-bold text-sm">
                              {{ p.display.slice(0,2).toUpperCase() }}
                            </div>
                            <div class="min-w-0">
                              <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ p.display }}</div>
                              <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ p.phone }}</div>
                            </div>
                            <span v-if="p.isAdmin" class="ml-2 text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300 flex-shrink-0">Admin</span>
                          </div>
                          <div class="flex items-center gap-2 flex-shrink-0">
                            <button @click="viewParticipant(p)" class="px-2 py-1 text-xs rounded bg-gray-100 dark:bg-zinc-700 text-gray-700 dark:text-gray-200">Info</button>
                            <button @click="startChatWith(p)" class="px-2 py-1 text-xs rounded bg-green-500 text-white hover:bg-green-600">Chat</button>
                          </div>
                        </div>
                      </div>
                      <div v-else class="mt-4">
                        <h5 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">Kontakt</h5>
                        <div class="flex items-center gap-3 mb-4">
                          <img 
                            v-if="participantProfilePictureUrl" 
                            :src="participantProfilePictureUrl" 
                            :alt="participantDisplay"
                            class="w-12 h-12 rounded-full object-cover"
                          />
                          <div v-else class="w-12 h-12 rounded-full bg-green-300 dark:bg-green-800 flex items-center justify-center text-green-700 dark:text-green-200 font-bold text-base">
                            {{ participantDisplay.slice(0,2).toUpperCase() }}
                          </div>
                          <div>
                            <div class="text-base font-bold text-gray-900 dark:text-gray-100">{{ participantDisplay }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ participantPhone }}</div>
                          </div>
                        </div>
                        <div class="mb-4">
                          <h6 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Über</h6>
                          <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ participantBio || 'Keine Beschreibung vorhanden' }}</p>
                        </div>
                        <div class="flex justify-end">
                          <button @click="selectedParticipant && startChatWith(selectedParticipant)" class="px-3 py-1.5 text-sm rounded bg-green-500 text-white hover:bg-green-600">Chat starten</button>
                        </div>
                      </div>
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
import { ref, computed, watch, onMounted } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import apiClient from '@/services/api';
import { API_CONFIG } from '@/config/api';

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true
  },
  chat: {
    type: Object as () => any,
    required: true
  },
  allChats: {
    type: Array as () => any[],
    required: false,
    default: () => []
  }
});

const emit = defineEmits(['close','start-chat','open-contacts']);

const closeModal = () => {
  // Reset to first level when closing
  clearParticipantView();
  emit('close');
};

// Computed properties for the chat info
const baseChat = computed(() => viewingParticipant.value && participantChat.value ? participantChat.value : props.chat);
const chatName = computed(() => baseChat.value?.name || 'Unbekannter Kontakt');
const isGroup = computed(() => baseChat.value?.is_group || false);
const isGroupView = computed(() => props.chat?.is_group || false);

const phoneNumber = computed(() => {
  const chat: any = baseChat.value;
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
  const c: any = baseChat.value;
  if (c?.contact_info?.profile_picture_url) {
    return proxyAvatarUrl(c.contact_info.profile_picture_url);
  }
  return null;
});

// Get description/bio
const description = computed(() => {
  const c: any = baseChat.value;
  if (isGroup.value) {
    return c?.contact_info?.description || null;
  } else {
    return c?.contact_info?.bio || c?.contact_info?.description || null;
  }
});

const viewingParticipant = ref(false);
const selectedParticipant = ref<{ jid: string; isAdmin?: boolean } | null>(null);
const participantChat = ref<any | null>(null);
const contacts = ref<any[]>([]);

const participantsList = computed(() => {
  const chat: any = props.chat;
  const fromMetadata = Array.isArray(chat?.metadata?.participants) ? chat.metadata.participants : [];
  if (fromMetadata.length > 0) {
    return fromMetadata.map((p: any) => {
      const jid = String(p?.jid || '');
      const phone = jid.replace(/@.*$/, '');
      const display = resolveParticipantDisplay(jid);
      const profilePictureUrl = resolveParticipantProfilePicture(jid);
      return { jid, phone: phone.startsWith('+') ? phone : '+' + phone, isAdmin: !!(p?.isAdmin || p?.isSuperAdmin), display, profilePictureUrl };
    });
  }
  const arr = Array.isArray(chat?.participants) ? chat.participants : [];
  return arr.filter((x: any) => x && String(x) !== 'me').map((raw: any) => {
    const jid = typeof raw === 'string' && raw.includes('@') ? raw : String(raw) + '@s.whatsapp.net';
    const phone = jid.replace(/@.*$/, '');
    const display = resolveParticipantDisplay(jid);
    const profilePictureUrl = resolveParticipantProfilePicture(jid);
    return { jid, phone: phone.startsWith('+') ? phone : '+' + phone, isAdmin: false, display, profilePictureUrl };
  });
});

function resolveParticipantDisplay(jid: string): string {
  // First check contacts table
  const normalizedJid = jid.includes('@') ? jid : jid + '@s.whatsapp.net';
  const contact = contacts.value.find((c: any) => {
    const contactPhone = c.phone.replace(/@.*$/, '');
    const jidPhone = normalizedJid.replace(/@.*$/, '');
    return contactPhone === jidPhone;
  });
  if (contact?.name) return contact.name;
  
  // Then check chats
  const chats: any[] = (props.allChats || []) as any[];
  const found = chats.find((c: any) => !c.is_group && (c?.metadata?.whatsapp_id === normalizedJid || (Array.isArray(c?.participants) && c.participants.includes(normalizedJid))));
  if (found?.name) return found.name;
  
  // Fallback to phone number
  const phone = normalizedJid.replace(/@.*$/, '');
  return phone ? '+' + phone : jid;
}

// Proxy avatar URLs through backend to avoid CORS issues
const proxyAvatarUrl = (url: string | null): string | null => {
  if (!url) return null;
  if (typeof url === 'string' && url.includes('ui-avatars.com')) return null;
  try {
    const u = new URL(String(url));
    if (typeof window !== 'undefined' && u.origin === window.location.origin) return String(url);
    return `${API_CONFIG.BASE_URL}/images/avatar?url=${encodeURIComponent(String(url))}`;
  } catch {
    return String(url);
  }
};

function resolveParticipantProfilePicture(jid: string): string | null {
  // First check contacts table
  const normalizedJid = jid.includes('@') ? jid : jid + '@s.whatsapp.net';
  const contact = contacts.value.find((c: any) => {
    const contactPhone = c.phone.replace(/@.*$/, '');
    const jidPhone = normalizedJid.replace(/@.*$/, '');
    return contactPhone === jidPhone;
  });
  if (contact?.profile_picture_url) return proxyAvatarUrl(contact.profile_picture_url);
  
  // Then check chats
  const chats: any[] = (props.allChats || []) as any[];
  const found = chats.find((c: any) => !c.is_group && (c?.metadata?.whatsapp_id === normalizedJid || (Array.isArray(c?.participants) && c.participants.includes(normalizedJid))));
  if (found?.contact_info?.profile_picture_url) return proxyAvatarUrl(found.contact_info.profile_picture_url);
  
  return null;
}

function resolveParticipantChat(jid: string): any | null {
  const chats: any[] = (props.allChats || []) as any[];
  const found = chats.find((c: any) => !c.is_group && (c?.metadata?.whatsapp_id === jid || (Array.isArray(c?.participants) && c.participants.includes(jid))));
  if (found) return found;
  return { name: resolveParticipantDisplay(jid), is_group: false, participants: [jid, 'me'], metadata: { whatsapp_id: jid }, contact_info: {} };
}

const participantDisplay = computed(() => resolveParticipantDisplay(selectedParticipant.value?.jid || ''));
const participantPhone = computed(() => {
  const jid = selectedParticipant.value?.jid || '';
  return jid ? '+' + jid.replace(/@.*$/, '') : '';
});
const participantBio = computed(() => {
  const c: any = participantChat.value;
  return c?.contact_info?.bio || c?.contact_info?.description || '';
});
const participantProfilePictureUrl = computed(() => {
  const jid = selectedParticipant.value?.jid || '';
  return jid ? resolveParticipantProfilePicture(jid) : null;
});

function viewParticipant(p: { jid: string }) {
  selectedParticipant.value = p;
  participantChat.value = resolveParticipantChat(p.jid);
  viewingParticipant.value = true;
}

function startChatWith(p: { jid: string }) {
  const jid = p.jid;
  emit('start-chat', jid);
}

function clearParticipantView() {
  viewingParticipant.value = false;
  selectedParticipant.value = null;
  participantChat.value = null;
}

function openAddContactModal() {
  const phone = participantPhone.value;
  emit('open-contacts', phone);
}

// Fetch contacts on mount and when modal opens
const fetchContacts = async () => {
  try {
    const response = await apiClient.get('/contacts');
    contacts.value = response.data.data || [];
  } catch (error) {
    console.error('Error fetching contacts:', error);
    contacts.value = [];
  }
};

onMounted(() => {
  fetchContacts();
});

// Watch for modal opening to reset to first level and refresh contacts
watch(() => props.isOpen, (isOpen) => {
  if (isOpen) {
    // Reset to first level when modal opens
    clearParticipantView();
    // Refresh contacts to get latest names
    fetchContacts();
  }
});
</script>
