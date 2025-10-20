<template>
  <div v-if="isOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" @click.self="$emit('close')">
    <div class="bg-white dark:bg-zinc-900 rounded-lg shadow-xl max-w-2xl w-full max-h-[80vh] flex flex-col">
      <!-- Header -->
      <div class="p-6 border-b border-gray-200 dark:border-zinc-800 flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Kontakte</h2>
        <button @click="$emit('close')" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Search -->
      <div class="p-4 border-b border-gray-200 dark:border-zinc-800">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Kontakte durchsuchen..."
          class="w-full px-4 py-2 border border-gray-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-400"
        />
      </div>

      <!-- Contacts List -->
      <div class="flex-1 overflow-y-auto p-4">
        <div v-if="loading" class="flex items-center justify-center py-8">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-500"></div>
        </div>

        <div v-else-if="filteredContacts.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
          <p>Keine Kontakte gefunden</p>
        </div>

        <div v-else class="space-y-2">
          <!-- Own Number Info Box -->
          <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border-2 border-green-200 dark:border-green-800 rounded-lg">
            <div class="flex items-center gap-3">
              <div class="w-12 h-12 rounded-full bg-green-500 dark:bg-green-700 flex items-center justify-center text-white font-bold text-lg shadow-md">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </div>
              <div class="flex-1">
                <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm">Deine Nummer</p>
                <p class="text-lg font-bold text-green-700 dark:text-green-400">+49 1590 8115183</p>
              </div>
            </div>
          </div>
          
          <!-- Contacts List -->
          <div
            v-for="contact in filteredContacts"
            :key="contact.id"
            class="flex items-center justify-between p-4 bg-gray-50 dark:bg-zinc-800 rounded-lg hover:bg-gray-100 dark:hover:bg-zinc-700 transition-colors"
          >
            <div class="flex items-center gap-3 flex-1">
              <div class="w-12 h-12 rounded-full bg-green-300 dark:bg-green-800 flex items-center justify-center text-green-700 dark:text-green-200 font-bold text-lg">
                {{ contact.name.slice(0, 2).toUpperCase() }}
              </div>
              <div class="flex-1">
                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ contact.name }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ contact.phone }}</p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button
                @click="editContact(contact)"
                class="p-2 text-blue-600 dark:text-gray-400 hover:bg-blue-50 dark:hover:bg-zinc-700 rounded-lg transition-colors"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                @click="openChat(contact)"
                class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors"
              >
                Chat öffnen
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Add Contact Button -->
      <div class="p-4 border-t border-gray-200 dark:border-zinc-800">
        <button
          @click="showAddContact = true"
          class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors font-semibold flex items-center justify-center gap-2"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Neuer Kontakt
        </button>
      </div>
    </div>

    <!-- Add/Edit Contact Modal -->
    <div v-if="showAddContact || editingContact" class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" @click.self="closeContactForm">
      <div class="bg-white dark:bg-zinc-900 rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4">
          {{ editingContact ? 'Kontakt bearbeiten' : 'Neuer Kontakt' }}
        </h3>
        
        <form @submit.prevent="saveContact" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
            <input
              v-model="contactForm.name"
              type="text"
              required
              class="w-full px-4 py-2 border border-gray-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-400"
              placeholder="z.B. Max Mustermann"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Telefonnummer</label>
            <input
              v-model="contactForm.phone"
              type="tel"
              required
              :disabled="!!editingContact"
              class="w-full px-4 py-2 border border-gray-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-400 disabled:bg-gray-100 dark:disabled:bg-zinc-700"
              placeholder="z.B. +49123456789"
            />
          </div>

          <!-- Info notice for editing contacts -->
          <div v-if="editingContact" class="bg-blue-50 dark:bg-zinc-800 border border-blue-200 dark:border-zinc-700 rounded-lg p-3">
            <div class="flex items-start gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="text-sm text-blue-800 dark:text-gray-300">
                Die Übernahme der Umbenennung in der Chat-Liste kann ein wenig dauern.
              </p>
            </div>
          </div>

          <div class="flex gap-2 pt-4">
            <button
              type="button"
              @click="closeContactForm"
              class="flex-1 py-2 border border-gray-300 dark:border-zinc-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors"
            >
              Abbrechen
            </button>
            <button
              type="submit"
              :disabled="saving"
              class="flex-1 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors disabled:bg-gray-300"
            >
              {{ saving ? 'Speichern...' : 'Speichern' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import apiClient from '@/services/api'

interface Contact {
  id: string
  name: string
  phone: string
}

interface Props {
  isOpen: boolean
  prefilledPhone?: string
  chatIdToUpdate?: string
}

const props = defineProps<Props>()
const emit = defineEmits<{
  close: []
  'chat-selected': [chatId: string]
}>()

const contacts = ref<Contact[]>([])
const loading = ref(false)
const searchQuery = ref('')
const showAddContact = ref(false)
const editingContact = ref<Contact | null>(null)
const saving = ref(false)

const contactForm = ref({
  name: '',
  phone: ''
})

const filteredContacts = computed(() => {
  // Define the user's own number
  const ownNumber = '4915908115183'
  
  // Filter contacts based on search query
  let filtered = contacts.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = contacts.value.filter(contact =>
      contact.name.toLowerCase().includes(query) ||
      contact.phone.includes(query)
    )
  }
  
  // Sort contacts: own number first, then alphabetically by name
  return filtered.sort((a, b) => {
    // Extract phone number without special characters for comparison
    const phoneA = a.phone.replace(/[^0-9]/g, '')
    const phoneB = b.phone.replace(/[^0-9]/g, '')
    
    // Check if either contact is the user's own number
    const isOwnA = phoneA === ownNumber || phoneA === `+${ownNumber}`
    const isOwnB = phoneB === ownNumber || phoneB === `+${ownNumber}`
    
    // If A is own number, it comes first
    if (isOwnA && !isOwnB) return -1
    // If B is own number, it comes first
    if (!isOwnA && isOwnB) return 1
    
    // Otherwise, sort alphabetically by name
    return a.name.localeCompare(b.name)
  })
})

const fetchContacts = async () => {
  loading.value = true
  try {
    const response = await apiClient.get('/chats')
    // Filter chats that have custom names (contacts)
    // Include chats that don't look like auto-generated names (not just phone numbers)
    contacts.value = response.data.data
      .filter((chat: any) => {
        if (!chat.name || chat.is_group) return false
        
        // Include if name doesn't look like a phone number (has letters or is a custom name)
        // OR if it's the user's own number
        const isPhoneNumberFormat = /^[+\d\s\-_@.]+$/.test(chat.name)
        const phoneNumber = chat.participants?.[0]?.replace(/[^0-9]/g, '') || ''
        const isOwnNumber = phoneNumber === '4915908115183'
        
        // Include if it's NOT a phone number format (custom name) OR if it's the user's own number
        return !isPhoneNumberFormat || isOwnNumber
      })
      .map((chat: any) => ({
        id: chat.id,
        name: chat.name,
        phone: chat.participants?.[0] || ''
      }))
    
    console.log('Fetched contacts:', contacts.value)
  } catch (error) {
    console.error('Error fetching contacts:', error)
  } finally {
    loading.value = false
  }
}

const editContact = (contact: Contact) => {
  editingContact.value = contact
  contactForm.value = {
    name: contact.name,
    phone: contact.phone
  }
}

const closeContactForm = () => {
  showAddContact.value = false
  editingContact.value = null
  contactForm.value = {
    name: '',
    phone: ''
  }
}

const saveContact = async () => {
  saving.value = true
  try {
    if (editingContact.value) {
      // Update existing contact
      await apiClient.put(`/chats/${editingContact.value.id}`, {
        name: contactForm.value.name
      })
    } else if (props.chatIdToUpdate) {
      // Update existing chat (from "Zu Kontakten hinzufügen")
      await apiClient.put(`/chats/${props.chatIdToUpdate}`, {
        name: contactForm.value.name
      })
    } else {
      // Normalize phone number to WhatsApp JID format
      let phoneNumber = contactForm.value.phone.trim()
      
      // Extract just the phone number part (before @)
      const numberPart = phoneNumber.replace(/@.*$/, '').replace(/^\+/, '').replace(/[\s\-\(\)]/g, '')
      
      // Always normalize to the full WhatsApp JID format
      phoneNumber = `${numberPart}@s.whatsapp.net`
      
      // Create new contact (create or update chat)
      await apiClient.post('/chats', {
        name: contactForm.value.name,
        participants: [phoneNumber],
        is_group: false
      })
    }
    
    await fetchContacts()
    closeContactForm()
  } catch (error) {
    console.error('Error saving contact:', error)
    alert('Fehler beim Speichern des Kontakts')
  } finally {
    saving.value = false
  }
}

const openChat = (contact: Contact) => {
  emit('chat-selected', contact.id)
  emit('close')
}

watch(() => props.isOpen, (isOpen) => {
  if (isOpen) {
    fetchContacts()
    
    // If a phone number is pre-filled, open the add contact form
    if (props.prefilledPhone) {
      showAddContact.value = true
      contactForm.value = {
        name: '',
        phone: props.prefilledPhone
      }
    }
  } else {
    // Reset form when modal closes
    closeContactForm()
  }
})
</script>
