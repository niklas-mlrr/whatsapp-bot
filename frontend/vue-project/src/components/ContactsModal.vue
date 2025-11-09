<template>
  <div v-if="isOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-2 md:p-4" @click.self="$emit('close')">
    <div class="bg-white dark:bg-zinc-900 rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] md:max-h-[80vh] flex flex-col">
      <!-- Header -->
      <div class="p-4 md:p-6 border-b border-gray-200 dark:border-zinc-800 flex items-center justify-between">
        <h2 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-gray-100">Kontakte</h2>
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
          <div class="mb-4 p-3 md:p-4 bg-green-50 dark:bg-green-900/20 border-2 border-green-200 dark:border-green-800 rounded-lg">
            <div class="flex items-center gap-2 md:gap-3">
              <div class="w-10 h-10 md:w-12 md:h-12 rounded-full bg-green-500 dark:bg-green-700 flex items-center justify-center text-white font-bold text-base md:text-lg shadow-md">
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
            class="flex flex-col sm:flex-row items-start sm:items-center gap-3 p-3 md:p-4 bg-gray-50 dark:bg-zinc-800 rounded-lg hover:bg-gray-100 dark:hover:bg-zinc-700 transition-colors"
          >
            <div class="flex items-center gap-2 md:gap-3 flex-1 w-full sm:w-auto">
              <div class="w-10 h-10 md:w-12 md:h-12 rounded-full bg-green-300 dark:bg-green-800 flex items-center justify-center text-green-700 dark:text-green-200 font-bold text-base md:text-lg flex-shrink-0">
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
                title="Kontakt bearbeiten"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                @click="confirmDeleteContact(contact)"
                class="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                title="Kontakt löschen"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
              <button
                @click="openChat(contact)"
                class="px-3 md:px-4 py-1.5 md:py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors text-sm md:text-base whitespace-nowrap"
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

          <!-- Info notice for contacts -->
          <div class="bg-blue-50 dark:bg-zinc-800 border border-blue-200 dark:border-zinc-700 rounded-lg p-3">
            <div class="flex items-start gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="text-sm text-blue-800 dark:text-gray-300">
                {{ editingContact ? 'Kontakte sind unabhängig von Chats. Änderungen betreffen nur den Kontakt.' : 'Sie können Kontakte hinzufügen, auch wenn noch kein Chat existiert.' }}
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
  profile_picture_url?: string
  bio?: string
  has_chat?: boolean
  chat_id?: number
}

interface Props {
  isOpen: boolean
  prefilledPhone?: string
  chatIdToUpdate?: string
  contactToEdit?: Contact | null
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
    const response = await apiClient.get('/contacts')
    contacts.value = response.data.data.map((contact: any) => ({
      id: contact.id,
      name: contact.name,
      phone: formatPhoneForDisplay(contact.phone),
      profile_picture_url: contact.profile_picture_url,
      bio: contact.bio,
      has_chat: contact.has_chat,
      chat_id: contact.chat_id
    }))
    
    console.log('Fetched contacts:', contacts.value)
  } catch (error) {
    console.error('Error fetching contacts:', error)
  } finally {
    loading.value = false
  }
}

const formatPhoneForDisplay = (phone: string): string => {
  // Remove @ suffix and format for display
  const cleaned = phone.replace(/@.*$/, '')
  return cleaned && /^\d+$/.test(cleaned) ? '+' + cleaned : cleaned
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
      await apiClient.put(`/contacts/${editingContact.value.id}`, {
        name: contactForm.value.name
      })
    } else {
      // Create new contact
      await apiClient.post('/contacts', {
        name: contactForm.value.name,
        phone: contactForm.value.phone
      })
    }
    
    await fetchContacts()
    closeContactForm()
    emit('close')
  } catch (error) {
    console.error('Error saving contact:', error)
    alert('Fehler beim Speichern des Kontakts')
  } finally {
    saving.value = false
  }
}

const openChat = async (contact: Contact) => {
  try {
    // If contact already has a chat, open it
    if (contact.has_chat && contact.chat_id) {
      emit('chat-selected', String(contact.chat_id))
      emit('close')
      return
    }
    
    // Otherwise, create a new chat for this contact
    const phoneNumber = contact.phone.replace(/^\+/, '').replace(/[\s\-\(\)]/g, '')
    const jid = `${phoneNumber}@s.whatsapp.net`
    
    await apiClient.post('/chats', {
      name: contact.name,
      participants: [jid],
      is_group: false
    })
    
    // Refresh contacts to get the updated chat_id
    await fetchContacts()
    
    // Find the updated contact and open its chat
    const updatedContact = contacts.value.find(c => c.id === contact.id)
    if (updatedContact?.chat_id) {
      emit('chat-selected', String(updatedContact.chat_id))
    }
    
    emit('close')
  } catch (error) {
    console.error('Error opening chat:', error)
    alert('Fehler beim Öffnen des Chats')
  }
}

const confirmDeleteContact = async (contact: Contact) => {
  if (!confirm(`Möchten Sie den Kontakt "${contact.name}" wirklich löschen? Der zugehörige Chat bleibt erhalten.`)) {
    return
  }
  
  try {
    // Delete the contact (chat remains untouched)
    await apiClient.delete(`/contacts/${contact.id}`)
    
    // Refresh contacts list
    await fetchContacts()
  } catch (error) {
    console.error('Error deleting contact:', error)
    alert('Fehler beim Löschen des Kontakts')
  }
}

watch(() => props.isOpen, (isOpen) => {
  if (isOpen) {
    fetchContacts()
    
    // If a contact to edit is provided, open the edit form
    if (props.contactToEdit) {
      editContact(props.contactToEdit)
    }
    // Otherwise, if a phone number is pre-filled, open the add contact form
    else if (props.prefilledPhone) {
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
