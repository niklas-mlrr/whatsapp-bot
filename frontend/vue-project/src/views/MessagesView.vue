<template>
  <div class="app-root bg-gray-100 dark:bg-black flex flex-row overflow-hidden relative">
    <!-- Mobile menu button (shown when sidebar is hidden) -->
    <button
      v-if="!showSidebarOnMobile && selectedChat"
      @click="showSidebarOnMobile = true"
      class="md:hidden fixed top-20 left-4 z-40 bg-green-500 text-white rounded-full p-3 shadow-lg hover:bg-green-600 transition-colors"
      aria-label="Open chat list"
    >
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
      </svg>
    </button>
    
    <!-- Logout overlay -->
    <div v-if="isLoggingOut" class="absolute inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
      <div class="bg-white rounded-lg p-8 flex flex-col items-center gap-4 shadow-xl">
        <svg class="animate-spin h-12 w-12 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-lg font-semibold text-gray-800">Abmelden...</p>
      </div>
    </div>
    <!-- Mobile sidebar backdrop -->
    <div
      v-if="showSidebarOnMobile"
      @click="showSidebarOnMobile = false"
      class="md:hidden fixed inset-0 bg-black bg-opacity-50 z-20 transition-opacity"
    ></div>
    
    <!-- Sidebar -->
    <aside :class="[
      'bg-white dark:bg-zinc-800 border-r border-gray-200 dark:border-zinc-700 flex flex-col h-full transition-transform duration-300',
      'w-full md:w-80',
      'fixed md:relative z-30 inset-y-0 left-0',
      showSidebarOnMobile ? 'translate-x-0' : '-translate-x-full md:translate-x-0'
    ]">
      <div class="p-4 font-bold text-lg border-b border-gray-200 dark:border-zinc-700 flex items-center justify-between dark:text-white">
        <div class="flex items-center gap-3">
          <!-- Close sidebar button (mobile only) -->
          <button
            v-if="selectedChat"
            @click="showSidebarOnMobile = false"
            class="md:hidden p-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors"
            aria-label="Close sidebar"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <span>Chats</span>
        </div>
        <div class="flex items-center gap-2">
          <!-- Dark mode toggle -->
          <button 
            @click="themeStore.toggleDarkMode()"
            class="p-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors"
            :title="themeStore.isDarkMode ? 'Light Mode' : 'Dark Mode'"
          >
            <!-- Sun icon for light mode -->
            <svg v-if="themeStore.isDarkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <!-- Moon icon for dark mode -->
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
          </button>
          <button 
            @click="handleLogout"
            :disabled="isLoggingOut"
            class="flex items-center gap-2 px-3 py-1.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            title="Abmelden"
          >
            <svg v-if="!isLoggingOut" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            <svg v-else class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Abmelden</span>
          </button>
        </div>
      </div>
      <div class="flex-1 overflow-y-auto overflow-x-hidden">
        <div v-if="loadingChats" class="text-blue-500 dark:text-gray-400 p-4">Chats werden geladen...</div>
        <div v-if="errorChats" class="text-red-500 dark:text-red-300 p-4">{{ errorChats }}</div>
        <div v-if="!loadingChats && !errorChats">
          <!-- Pending Chats Section (shown at top) -->
          <div v-if="pendingChats.length > 0" class="border-b-2 border-gray-300 dark:border-zinc-600">
            <div class="neue-nachrichten-indicator px-4 py-2 bg-yellow-100 text-sm font-semibold text-gray-800 border-b border-gray-200 dark:bg-zinc-900 dark:text-yellow-400 dark:border-zinc-700">
              Neue Nachrichten
            </div>
            <div v-for="chat in pendingChats" :key="chat.id" class="px-4 py-3 border-b border-gray-100 dark:border-zinc-700 bg-yellow-50 dark:bg-yellow-900/10 overflow-hidden">
              <div class="flex items-center justify-between mb-2 min-w-0">
                <span class="font-medium text-gray-800 dark:text-gray-200 truncate">{{ getChatDisplayName(chat) }}</span>
              </div>
              <p v-if="chat.last_message_preview" class="text-sm text-gray-700 dark:text-gray-300 mb-2 italic break-words">{{ chat.last_message_preview }}</p>
              <b class="text-sm text-gray-600 dark:text-gray-400 mb-3">Möchtest du diesen Chat zulassen?</b>
              <div class="flex gap-2">
                <button
                  @click="approveChat(chat)"
                  class="flex-1 px-3 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors text-sm font-semibold"
                >
                  Annehmen
                </button>
                <button
                  @click="rejectChat(chat)"
                  class="flex-1 px-3 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors text-sm font-semibold"
                >
                  Löschen
                </button>
              </div>
            </div>
          </div>
          
          <!-- Approved Chats -->
          <ul>
            <li v-for="chat in approvedChats" :key="chat.id" 
                @click="selectChat(chat)"
                :class="['cursor-pointer px-4 py-3 border-b border-gray-100 dark:border-zinc-700 hover:bg-green-50 dark:hover:bg-zinc-700 relative group flex items-center justify-between dark:text-gray-200 min-w-0', selectedChat && selectedChat.id === chat.id ? 'bg-green-100 dark:bg-green-900/20 font-bold' : '']">
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <div class="w-8 h-8 rounded-full overflow-hidden bg-green-300 flex items-center justify-center text-green-700 font-bold" :key="'lst-'+chat.id+'-'+(chat.contact_info_updated_at||'no')">
                  <img
                    v-if="chatAvatarUrl(chat)"
                    :src="chatAvatarUrl(chat) as any"
                    alt="Avatar"
                    class="w-8 h-8 object-cover"
                    referrerpolicy="no-referrer"
                    @error="handleAvatarError(chat)"
                  />
                  <span v-else>{{ getChatDisplayName(chat).slice(0,2).toUpperCase() }}</span>
                </div>
                <span class="flex-1 truncate">{{ getChatDisplayName(chat) }}</span>
                <span
                  v-if="formatChatTime(chat)"
                  :class="['shrink-0 text-xs font-normal', chat.unread_count > 0 && (!selectedChat || selectedChat.id !== chat.id) ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400']"
                  :title="chat.last_message_at ? new Date(chat.last_message_at).toLocaleString('de-DE') : ''"
                >{{ formatChatTime(chat) }}</span>
                <!-- Pin icon for the own-number chat (always sorted on top) -->
                <svg
                  v-if="isOwnChat(chat)"
                  class="shrink-0 h-4 w-4 rotate-45 text-gray-400 dark:text-gray-500"
                  xmlns="http://www.w3.org/2000/svg"
                  viewBox="0 0 24 24"
                  fill="currentColor"
                  role="img"
                  aria-label="Angeheftet"
                >
                  <title>Angeheftet</title>
                  <path d="M16 9V4h1a1 1 0 0 0 0-2H7a1 1 0 0 0 0 2h1v5c0 1.66-1.34 3-3 3v2h5.97v7l1 1 1-1v-7H19v-2c-1.66 0-3-1.34-3-3z" />
                </svg>
                <!-- Unread message indicator -->
                <span 
                  v-if="chat.unread_count && chat.unread_count > 0 && (!selectedChat || selectedChat.id !== chat.id)"
                  class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 bg-green-500 text-white text-xs font-bold rounded-full"
                  :title="`${chat.unread_count} ungelesene Nachricht${chat.unread_count > 1 ? 'en' : ''}`"
                >
                  {{ chat.unread_count > 99 ? '99+' : chat.unread_count }}
                </span>
              </div>
              <button 
                @click.stop="confirmDeleteChat(chat)"
                class="opacity-0 group-hover:opacity-100 transition-opacity p-2 hover:bg-red-100 rounded-full"
                title="Chat löschen"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </li>
          </ul>
        </div>
      </div>
      <!-- Action Buttons -->
      <div class="p-4 border-t border-gray-200 dark:border-zinc-700 space-y-2">
        <button
          @click="startNewChat"
          class="w-full py-3 bg-blue-500 dark:bg-zinc-700 text-white rounded-lg hover:bg-blue-600 dark:hover:bg-zinc-600 transition-colors font-semibold flex items-center justify-center gap-2"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
          Neuer Chat
        </button>
        <button
          @click="showContactsModal = true"
          class="w-full py-3 bg-green-600 dark:bg-green-700 text-white rounded-lg hover:bg-green-600 dark:hover:bg-green-600 transition-colors font-semibold flex items-center justify-center gap-2"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
          Kontakte
        </button>
      </div>
    </aside>
    <!-- Main chat area -->
    <main class="flex-1 flex flex-col h-full min-h-0 overflow-hidden">
      <!-- Chat header (only shown when chat is selected) -->
      <div v-if="selectedChat" class="flex items-center gap-3 px-4 md:px-6 py-3 md:py-4 bg-white dark:bg-zinc-900 border-b border-gray-200 dark:border-zinc-800 shadow-sm min-h-[56px] md:min-h-[64px] sticky top-0 z-10">
        <div class="w-10 h-10 rounded-full overflow-hidden bg-green-300 flex items-center justify-center text-green-700 font-bold text-lg" :key="'hdr-'+selectedChatAvatarKey">
          <img
            v-if="chatAvatarUrl(selectedChat)"
            :src="chatAvatarUrl(selectedChat) as any"
            alt="Avatar"
            class="w-10 h-10 object-cover"
            referrerpolicy="no-referrer"
            @error="handleAvatarError(selectedChat)"
          />
          <span v-else>{{ getChatDisplayName(selectedChat).slice(0,2).toUpperCase() }}</span>
        </div>
        <div class="flex flex-col flex-1 cursor-pointer" @click="openContactInfo">
          <span class="font-semibold text-lg text-gray-900 dark:text-gray-100 hover:text-green-600 dark:hover:text-green-400 transition-colors">
            {{ getChatDisplayName(selectedChat) }}
          </span>
        </div>
        <!-- Add to contacts button (shown when chat name looks like a phone number AND no contact exists) -->
        <button
          v-if="shouldShowAddToContactsButton"
          @click="addToContacts"
          class="flex items-center gap-2 px-3 md:px-4 py-1.5 md:py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors text-xs md:text-sm"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Zu Kontakten hinzufügen
        </button>
      </div>
      
      <!-- Messages area -->
      <div class="flex-1 min-h-0">
        <MessageList 
          v-if="selectedChat"
          :key="`${selectedChat.id}-${contactsVersion}`"
          ref="messageListRef" 
          :chat="selectedChat.id"
          :current-user="currentUserForChat"
          :is-group-chat="!!selectedChat.is_group"
          :members="membersForChat"
          @reply-to-message="handleReplyToMessage"
          @edit-message="handleEditMessage"
          @message-read="handleChatMessagesRead"
        />
        <div v-else class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400 bg-white dark:bg-zinc-800">
          <div v-if="loadingChats" class="text-center">
            Laden...
            <br>
            Bitte warten...
          </div>
          <div v-else>
            Wähle einen Chat, um Nachrichten zu senden
          </div>
        </div>
      </div>
      
      <!-- Message input -->
      <div class="flex-shrink-0 bg-white dark:bg-zinc-900 border-t border-gray-200 dark:border-zinc-800 z-10 pb-[calc(env(safe-area-inset-bottom)+0.5rem)]">
        <!-- Edit preview -->
        <div v-if="editingMessage" class="bg-blue-50 dark:bg-blue-900/20 px-6 py-3 border-b border-blue-200 dark:border-blue-800 flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <svg class="w-4 h-4 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
              </svg>
              <span class="text-xs font-semibold text-blue-600 dark:text-blue-400">Nachricht bearbeiten</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300 truncate">{{ editingMessage.content }}</p>
          </div>
          <button
            @click="cancelEdit"
            class="flex-shrink-0 text-blue-400 hover:text-blue-600 dark:text-blue-500 dark:hover:text-blue-300 transition-colors"
            title="Bearbeitung abbrechen"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
        
        <!-- Reply preview -->
        <div v-else-if="replyToMessage" class="bg-gray-50 dark:bg-zinc-800 px-6 py-3 border-b border-gray-200 dark:border-zinc-700 flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <svg class="w-4 h-4 text-gray-500 dark:text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
              </svg>
              <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">Antwort an {{ replySenderName }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300 truncate">{{ replyToMessage.content || '[Medien]' }}</p>
          </div>
          <button
            @click="clearReplyToMessage"
            class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 transition-colors"
            title="Antwort entfernen"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
        
        <!-- Attachment preview -->
        <div v-if="attachmentPreviewVisible" class="relative bg-gray-50 dark:bg-zinc-800 p-3 border-b border-gray-200 dark:border-zinc-700">
          <div v-if="isImageAttachment" class="max-w-[220px] relative">
            <img :src="attachmentPreviewUrl || undefined" alt="Preview" class="max-h-36 rounded-lg" />
            <button
              type="button"
              @click="clearAttachment"
              class="absolute -right-2 -top-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition-colors"
              title="Anhang entfernen"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <div v-if="isUploadingAttachment" class="absolute inset-x-0 bottom-2 flex justify-center">
              <span class="text-xs bg-black bg-opacity-60 text-white px-2 py-0.5 rounded-full">Wird hochgeladen...</span>
            </div>
          </div>
          <div v-else class="relative flex items-start gap-3 bg-white dark:bg-zinc-800 border border-gray-200 dark:border-zinc-700 rounded-lg p-4 pr-12 max-w-lg">
            <div class="p-2 bg-gray-100 dark:bg-zinc-700 rounded-full">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10l5 5m0 0l5-5m-5 5V6" />
              </svg>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">{{ attachmentDisplayName }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ formattedAttachmentSize }}
                <span v-if="attachmentDisplayMimetype"> • {{ attachmentDisplayMimetype }}</span>
              </p>
              <p v-if="isUploadingAttachment" class="text-xs text-gray-500 mt-1">Wird hochgeladen...</p>
            </div>
            <button
              type="button"
              @click="clearAttachment"
              class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition-colors"
              title="Anhang entfernen"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
        
        <!-- Formatting toolbar (WhatsApp syntax) -->
        <div v-if="showFormatBar && selectedChat" class="flex flex-wrap items-center gap-1 px-3 md:px-6 pt-2" role="toolbar" aria-label="Textformatierung">
          <button
            v-for="f in formatButtons"
            :key="f.key"
            type="button"
            @mousedown.prevent
            @click="applyFormat(f)"
            :title="f.title"
            :aria-label="f.title"
            class="h-8 min-w-[2rem] px-2 rounded-md text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-zinc-700 focus:outline-none focus:ring-2 focus:ring-green-400"
          >
            <span :class="f.cls">{{ f.label }}</span>
          </button>
        </div>

        <!-- Live preview of formatted text -->
        <div v-if="composerPreview" class="mx-3 md:mx-6 mt-2 px-3 py-2 rounded-lg bg-green-50 dark:bg-zinc-800 border border-green-200 dark:border-zinc-700 text-sm text-gray-800 dark:text-gray-200 max-h-32 overflow-y-auto">
          <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-0.5">Vorschau</div>
          <div class="whitespace-pre-wrap break-words" v-html="composerPreview"></div>
        </div>

        <form @submit.prevent="sendMessageHandler" class="flex items-center gap-2 px-3 md:px-6 py-3 md:py-4 relative">
        <textarea
          ref="messageInput"
          v-model="input"
          :disabled="!selectedChat"
          rows="1"
          placeholder="Nachricht eingeben"
          @input="adjustTextareaHeight"
          @keydown="onComposerKeydown"
          :class="textareaClasses"
        ></textarea>
        <button type="button" @click="toggleFormatBar" @mousedown.prevent :disabled="!selectedChat"
          :title="showFormatBar ? 'Formatierung ausblenden' : 'Formatierung (fett, kursiv, ...)'"
          :aria-pressed="showFormatBar"
          :class="['shrink-0 h-10 w-10 rounded-full border text-sm font-semibold focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed',
            showFormatBar
              ? 'border-green-500 bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300 dark:border-green-600'
              : 'border-gray-300 dark:border-zinc-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-zinc-700']">
          Aa
        </button>
        <button type="button" @click="insertLineBreak" @mousedown.prevent :disabled="!selectedChat" title="Zeilenumbruch einfügen"
          class="shrink-0 h-10 px-2 rounded-full border border-gray-300 dark:border-zinc-600 text-gray-600 dark:text-gray-300 font-mono text-sm hover:bg-gray-100 dark:hover:bg-zinc-700 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed">
          &lt;br&gt;
        </button>
        <div class="relative">
          <button type="button" @click="openMenu" :disabled="!selectedChat"
            class="inline-block w-10 h-10 bg-green-500 dark:bg-green-600 rounded-full flex items-center justify-center text-white md:ml-2 focus:outline-none disabled:bg-gray-200 dark:disabled:bg-zinc-700 hover:bg-green-600 dark:hover:bg-green-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
          </button>
          <div v-if="showMenu" class="absolute left-0 bottom-12 z-10 bg-white dark:bg-zinc-800 border border-gray-200 dark:border-zinc-700 rounded shadow-lg min-w-[160px]">
            <ul>
              <li @click="selectAddImage" class="px-4 py-2 hover:bg-green-50 dark:hover:bg-zinc-700 cursor-pointer dark:text-gray-200">Bild hinzufügen</li>
              <li @click="selectAddFile" class="px-4 py-2 hover:bg-green-50 dark:hover:bg-zinc-700 cursor-pointer dark:text-gray-200">Datei hinzufügen</li>
              <li @click="selectCreatePoll" class="px-4 py-2 hover:bg-green-50 dark:hover:bg-zinc-700 cursor-pointer dark:text-gray-200">Umfrage erstellen</li>
            </ul>
          </div>
        </div>
        <input id="image-upload-input" type="file" accept="image/*" class="hidden" @change="onAttachmentChange" :disabled="!selectedChat" />
        <input id="file-upload-input" type="file" class="hidden" @change="onAttachmentChange" :disabled="!selectedChat" />
        <button
          type="submit"
          :disabled="!canSend"
          :title="isSending ? 'Wird gesendet...' : 'Senden'"
          :aria-label="isSending ? 'Wird gesendet...' : 'Senden'"
          class="shrink-0 bg-green-500 dark:bg-green-600 text-white rounded-full h-10 w-10 md:w-auto md:h-auto md:px-6 md:py-2 font-semibold hover:bg-green-600 dark:hover:bg-green-500 disabled:bg-gray-300 dark:disabled:bg-zinc-700 disabled:cursor-not-allowed flex items-center gap-2 md:min-w-[80px] justify-center text-sm md:text-base"
        >
          <svg v-if="isSending" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <!-- Paper plane icon on small screens, text label from md up -->
          <svg v-if="!isSending" class="h-5 w-5 rotate-90 md:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
          </svg>
          <span class="hidden md:inline">{{ isSending ? 'Wird gesendet...' : 'Senden' }}</span>
        </button>
        </form>
      </div>
    </main>
    
    <!-- Contacts Modal -->
    <ContactsModal
      :is-open="showContactsModal"
      :prefilled-phone="prefilledContactPhone"
      :chat-id-to-update="chatIdToUpdate"
      :contact-to-edit="contactToEdit"
      @close="handleContactsModalClose"
      @chat-selected="handleChatSelected"
    />

    <!-- Contact Info Modal -->
    <ContactInfoModal
      v-if="selectedChat"
      :is-open="showContactInfoModal"
      :chat="selectedChat"
      :all-chats="chats"
      @close="showContactInfoModal = false"
      @start-chat="handleStartChatFromModal"
      @open-contacts="handleOpenContactsFromModal"
    />

    <!-- New Chat Modal -->
    <div v-if="showNewChatModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" @click.self="closeNewChatModal">
      <div class="bg-white dark:bg-zinc-900 rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4">Neuer Chat</h3>
        
        <form @submit.prevent="createNewChat" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Telefonnummer</label>
            <input
              v-model="newChatPhone"
              type="tel"
              required
              autofocus
              class="w-full px-4 py-2 border border-gray-300 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-400"
              placeholder="z.B. 4917646765869 oder +4917646765869"
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Geben Sie die Telefonnummer mit oder ohne + ein</p>
          </div>

          <div class="flex gap-2 pt-4">
            <button
              type="button"
              @click="closeNewChatModal"
              class="flex-1 py-2 border border-gray-300 dark:border-zinc-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors"
            >
              Abbrechen
            </button>
            <button
              type="submit"
              :disabled="isCreatingChat || !newChatPhone.trim()"
              class="flex-1 py-2 bg-blue-500 dark:bg-blue-600 text-white rounded-lg hover:bg-blue-600 dark:hover:bg-blue-500 transition-colors disabled:bg-gray-300 dark:disabled:bg-zinc-700"
            >
              {{ isCreatingChat ? 'Erstellen...' : 'Chat erstellen' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Poll Creation Modal -->
    <div v-if="showPollModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" @click.self="closePollModal">
      <div class="bg-white dark:bg-zinc-800 rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6">
          <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4">📊 Umfrage erstellen</h3>
          
          <form @submit.prevent="createPoll" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Frage</label>
              <input
                v-model="pollQuestion"
                type="text"
                required
                autofocus
                maxlength="255"
                class="w-full px-4 py-2 border border-gray-300 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-400"
                placeholder="Geben Sie Ihre Frage ein..."
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Antwortoptionen</label>
              <div class="space-y-2">
                <div v-for="(option, index) in pollOptions" :key="index" class="flex gap-2">
                  <input
                    v-model="pollOptions[index]"
                    type="text"
                    required
                    maxlength="100"
                    class="flex-1 px-3 py-2 border border-gray-300 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-400"
                    :placeholder="`Option ${index + 1}`"
                  />
                  <button
                    v-if="pollOptions.length > 2"
                    type="button"
                    @click="removePollOption(index)"
                    class="px-3 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                  </button>
                </div>
              </div>
              
              <button
                v-if="pollOptions.length < 12"
                type="button"
                @click="addPollOption"
                class="mt-2 text-sm text-green-600 dark:text-green-400 hover:text-green-700 dark:hover:text-green-300 transition-colors"
              >
                + Option hinzufügen
              </button>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Auswahltyp</label>
              <div class="space-y-3">
                <label class="flex items-center p-3 border border-gray-200 dark:border-zinc-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-zinc-700 transition-colors">
                  <input
                    v-model="allowMultipleSelection"
                    type="radio"
                    :value="false"
                    class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 focus:ring-green-500 dark:focus:ring-green-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
                  />
                  <div class="ml-3">
                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Einfachauswahl</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">Nutzer können nur eine Option wählen</span>
                  </div>
                </label>
                <label class="flex items-center p-3 border border-gray-200 dark:border-zinc-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-zinc-700 transition-colors">
                  <input
                    v-model="allowMultipleSelection"
                    type="radio"
                    :value="true"
                    class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 focus:ring-green-500 dark:focus:ring-green-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
                  />
                  <div class="ml-3">
                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Mehrfachauswahl</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">Nutzer können mehrere Optionen wählen</span>
                  </div>
                </label>
              </div>
            </div>

            <div class="flex gap-2 pt-4">
              <button
                type="button"
                @click="closePollModal"
                class="flex-1 py-2 border border-gray-300 dark:border-zinc-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors"
              >
                Abbrechen
              </button>
              <button
                type="submit"
                :disabled="isCreatingPoll || !pollQuestion.trim() || pollOptions.length < 2 || pollOptions.some(opt => !opt.trim())"
                class="flex-1 py-2 bg-green-500 dark:bg-green-600 text-white rounded-lg hover:bg-green-600 dark:hover:bg-green-500 transition-colors disabled:bg-gray-300 dark:disabled:bg-zinc-700"
              >
                {{ isCreatingPoll ? 'Wird erstellt...' : 'Umfrage senden' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { Dialog } from '@headlessui/vue'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import MessageList from '../components/MessageList.vue'
import ContactsModal from '../components/ContactsModal.vue'
import ContactInfoModal from '../components/ContactInfoModal.vue'
import { fetchChats, sendMessage, uploadFile, deleteChat } from '../api/messages'
import { API_CONFIG } from '@/config/api'
import { useWebSocket } from '@/services/websocket'
import apiClient from '@/services/api'
import {
  renderWhatsAppText,
  hasWhatsAppFormatting,
  toggleInlineFormat,
  toggleLinePrefix,
  shiftIndent,
  insertNewline,
  parseLine,
  type InlineFormat,
  type LineFormat,
} from '@/utils/whatsappFormat'

// Initialize theme store
const themeStore = useThemeStore()

const updateViewportHeightVar = () => {
  try {
    if (typeof window === 'undefined') return
    const vh = window.innerHeight * 0.01
    document.documentElement.style.setProperty('--vh', `${vh}px`)
  } catch {}
}

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
const contacts = ref<any[]>([])
const contactsVersion = ref(0)
const loadingChats = ref(false)
const errorChats = ref<string | null>(null)
const selectedChat = ref<any | null>(null)
const showSidebarOnMobile = ref(true)
const avatarLoadFailed = ref<Record<string, boolean>>({})

const chatAvatarUrl = (c: any) => {
  if (!c) return null
  if (avatarLoadFailed.value?.[String(c.id)]) return null
  // Prefer explicit profile picture URLs we stored; avoid generated ui-avatars to prevent CORS
  const url = c?.contact_info?.profile_picture_url || c?.metadata?.profile_picture_url || c?.metadata?.avatar_url || null
  if (!url) return null
  if (typeof url === 'string' && url.includes('ui-avatars.com')) return null
  try {
    const u = new URL(String(url))
    if (typeof window !== 'undefined' && u.origin === window.location.origin) return String(url)
    return `${API_CONFIG.BASE_URL}/images/avatar?url=${encodeURIComponent(String(url))}`
  } catch {
    return String(url)
  }
}

const handleAvatarError = (c: any) => {
  if (c && c.id != null) {
    avatarLoadFailed.value[String(c.id)] = true
  }
}

// Fetch contacts
const fetchContacts = async () => {
  try {
    const response = await apiClient.get('/contacts')
    contacts.value = response.data.data || []
    contactsVersion.value++ // Increment to trigger MessageList refresh
  } catch (error) {
    console.error('Error fetching contacts:', error)
    contacts.value = []
  }
}

// Get display name for a chat (checks contacts first)
const getChatDisplayName = (chat: any): string => {
  if (!chat) return 'Unknown'
  
  // For groups, always use the chat name
  if (chat.is_group) return chat.name || 'Group'
  
  // For direct chats, check if there's a matching contact
  const phoneNumber = chat.metadata?.whatsapp_id || chat.participants?.[0] || chat.original_name || ''
  if (phoneNumber) {
    const normalizedPhone = phoneNumber.replace(/@.*$/, '')
    const contact = contacts.value.find((c: any) => {
      const contactPhone = c.phone.replace(/@.*$/, '')
      return contactPhone === normalizedPhone
    })
    if (contact?.name) return contact.name
  }
  
  // Fallback to chat name
  return chat.name || 'Unknown'
}

const selectedChatAvatarUrl = computed(() => selectedChat.value?.contact_info?.profile_picture_url || null)
const selectedChatAvatarKey = computed(() => {
  const c: any = selectedChat.value
  if (!c) return 'no'
  return `${c.id}-${c.contact_info_updated_at || 'no'}-${c?.contact_info?.profile_picture_url || 'no'}`
})

const syncSelectedChat = (newChats: any[]) => {
  if (!selectedChat.value) return
  const updated = newChats.find((c: any) => c.id === selectedChat.value.id)
  if (updated) {
    selectedChat.value = updated
  }
}

// The bot's own number (notes-to-self chat) stays pinned on top of the list
const OWN_NUMBER_PATTERNS = ['1590 8115183', '15908115183', '4915908115183', '+4915908115183', '49 1590 8115183']

const isOwnChat = (chat: any): boolean => {
  // Groups list every member incl. our own number in participants -> never the own chat
  if (chat.is_group) return false
  const matches = (val: unknown) => typeof val === 'string' && OWN_NUMBER_PATTERNS.some(p => val.includes(p))
  if (matches(chat.name) || matches(chat.metadata?.whatsapp_id) || matches(chat.original_name)) return true
  return Array.isArray(chat.participants) && chat.participants.some((p: unknown) => matches(String(p)))
}

// Time of the most recent message (falls back to chat creation for empty chats)
const chatActivityTime = (chat: any): number => {
  const t = Date.parse(chat?.last_message_at || chat?.created_at || '')
  return Number.isNaN(t) ? 0 : t
}

// WhatsApp-style label: time today, "Gestern", weekday within a week, else date
const formatChatTime = (chat: any): string => {
  if (!chat?.last_message_at) return ''
  const t = chatActivityTime(chat)
  if (!t) return ''
  const d = new Date(t)
  const now = new Date()
  const dayStart = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const days = Math.round((dayStart(now) - dayStart(d)) / 86400000)
  if (days <= 0) return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
  if (days === 1) return 'Gestern'
  if (days < 7) return d.toLocaleDateString('de-DE', { weekday: 'long' })
  return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

// Optimistically move a chat to the top after sending; the next poll confirms it
const bumpChatActivity = (chatId: string | number) => {
  const chat = chats.value.find(c => String(c.id) === String(chatId))
  if (chat) chat.last_message_at = new Date().toISOString()
}

// Separate pending and approved chats; approved ones sorted by most recent message
const approvedChats = computed(() =>
  chats.value
    .filter(c => !c.pending_approval)
    .sort((a, b) => {
      const aOwn = isOwnChat(a)
      const bOwn = isOwnChat(b)
      if (aOwn !== bOwn) return aOwn ? -1 : 1
      return chatActivityTime(b) - chatActivityTime(a) || Number(b.id) - Number(a.id)
    })
)
const pendingChats = computed(() => chats.value.filter(c => c.pending_approval))
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
const typingTimeout = ref<number | null>(null)
const isLoggingOut = ref(false)
const showContactsModal = ref(false)
const showContactInfoModal = ref(false)
const prefilledContactPhone = ref<string | undefined>(undefined)
const chatIdToUpdate = ref<string | undefined>(undefined)
const contactToEdit = ref<any | null>(null)
const showNewChatModal = ref(false)
const newChatPhone = ref('')
const isCreatingChat = ref(false)
const messageInput = ref<HTMLTextAreaElement | null>(null)
const textareaHeight = ref<number>(40) // Start with min height (2.5rem = 40px)
const replyToMessage = ref<any | null>(null)
const editingMessage = ref<any | null>(null)

// Poll creation state
const showPollModal = ref(false)
const pollQuestion = ref('')
const pollOptions = ref(['', ''])
const isCreatingPoll = ref(false)
const allowMultipleSelection = ref(false)

// WebSocket for typing indicators and new chat notifications
const { notifyTyping, connect: connectWebSocket } = useWebSocket()

// Check if a string looks like a phone number (contains numbers, +, _, or -)
const isPhoneNumber = (name: string): boolean => {
  return /^[+\d_\-\s]+$/.test(name) || name.includes('_')
}

// Check if the selected chat has a contact associated with it
const selectedChatHasContact = computed((): boolean => {
  if (!selectedChat.value || selectedChat.value.is_group) return false
  
  const phoneNumber = selectedChat.value.metadata?.whatsapp_id || selectedChat.value.participants?.[0] || ''
  if (!phoneNumber) return false
  
  const normalizedPhone = phoneNumber.replace(/@.*$/, '')
  return contacts.value.some((c: any) => {
    const contactPhone = c.phone.replace(/@.*$/, '')
    return contactPhone === normalizedPhone
  })
})

// Show "Add to Contacts" button if chat name looks like a phone number AND no contact exists
const shouldShowAddToContactsButton = computed((): boolean => {
  if (!selectedChat.value) return false
  return isPhoneNumber(selectedChat.value.name) && !selectedChatHasContact.value
})

// Add current chat to contacts
const addToContacts = async () => {
  if (!selectedChat.value) return
  
  // Extract phone number from the chat
  let phoneNumber = selectedChat.value.participants?.[0] 
                    || selectedChat.value.metadata?.whatsapp_id 
                    || selectedChat.value.original_name
                    || selectedChat.value.name
  
  // Clean up the phone number for display (remove @ suffix and normalize)
  phoneNumber = phoneNumber.replace(/@.*$/, '') // Remove everything after @
  
  // Add + prefix if it's just digits (for better UX)
  if (/^\d+$/.test(phoneNumber)) {
    phoneNumber = '+' + phoneNumber
  }
  
  // Check if contact already exists
  try {
    const response = await apiClient.post('/contacts/find-by-phone', { phone: phoneNumber })
    if (response.data.data) {
      // Contact exists - open edit mode
      contactToEdit.value = {
        id: response.data.data.id,
        name: response.data.data.name,
        phone: phoneNumber
      }
      prefilledContactPhone.value = undefined
    } else {
      // Contact doesn't exist - open add mode
      contactToEdit.value = null
      prefilledContactPhone.value = phoneNumber
    }
  } catch (error) {
    // If error, assume contact doesn't exist
    contactToEdit.value = null
    prefilledContactPhone.value = phoneNumber
  }
  
  showContactsModal.value = true
}

// Handle contacts modal close
const handleContactsModalClose = async () => {
  showContactsModal.value = false
  prefilledContactPhone.value = undefined // Reset prefilled phone
  chatIdToUpdate.value = undefined // Reset chat ID to update
  contactToEdit.value = null // Reset contact to edit
  
  // Refresh contacts to update display names in chat list
  await fetchContacts()
}

// Handle chat selection from contacts modal
const handleChatSelected = (chatId: string) => {
  const chat = chats.value.find(c => c.id === chatId)
  if (chat) {
    selectChat(chat)
  }
}

// Open contact info modal
const openContactInfo = () => {
  if (selectedChat.value) {
    showContactInfoModal.value = true
  }
}

// Start a direct chat from ContactInfoModal participant selection
const handleStartChatFromModal = async (participantJid: string) => {
  if (!participantJid) return
  try {
    // Normalize JID to phone@s.whatsapp.net
    let jid = participantJid
    if (!jid.includes('@')) {
      const number = jid.replace(/^\+/, '')
      jid = `${number}@s.whatsapp.net`
    } else if (!jid.endsWith('@s.whatsapp.net')) {
      // convert any other suffix to s.whatsapp.net for direct chat
      jid = jid.replace(/@.*$/, '@s.whatsapp.net')
    }

    // Create or update chat for this contact
    await apiClient.post('/chats', {
      name: jid,
      participants: [jid],
      is_group: false
    })

    // Refresh chats and select the created/found chat
    const response = await fetchChats()
    if (response && response.data && response.data.data) {
      chats.value = response.data.data
      const target = chats.value.find((c: any) => c?.metadata?.whatsapp_id === jid || c?.original_name === jid)
      if (target) {
        selectChat(target)
      }
    }

    // Close modal
    showContactInfoModal.value = false
  } catch (error: any) {
    console.error('Error starting chat from modal:', error)
    alert(error?.response?.data?.message || 'Fehler beim Öffnen des Chats')
  }
}

// Open contacts modal from ContactInfoModal with prefilled phone
const handleOpenContactsFromModal = async (phone: string) => {
  if (!phone) return
  
  // Close the contact info modal
  showContactInfoModal.value = false
  
  // Check if contact already exists using the new contacts API
  try {
    const response = await apiClient.post('/contacts/find-by-phone', { phone })
    if (response.data.data) {
      // Contact exists - open edit mode
      contactToEdit.value = {
        id: response.data.data.id,
        name: response.data.data.name,
        phone: phone
      }
      prefilledContactPhone.value = undefined
    } else {
      // Contact doesn't exist - open add mode
      contactToEdit.value = null
      prefilledContactPhone.value = phone
    }
  } catch (error) {
    // If error, assume contact doesn't exist
    contactToEdit.value = null
    prefilledContactPhone.value = phone
  }
  
  showContactsModal.value = true
}

// Watch for changes to input field to send typing notifications
watch(input, (newVal: string, oldVal: string) => {
  // Send typing notification when user is typing
  if (selectedChat.value && newVal && newVal !== oldVal) {
    // Clear previous timeout
    if (typingTimeout.value) {
      clearTimeout(typingTimeout.value)
    }
    
    // Notify that user is typing
    notifyTyping(selectedChat.value.id.toString(), true)
    
    // Set timeout to stop typing indicator after 2 seconds of inactivity
    typingTimeout.value = window.setTimeout(() => {
      if (selectedChat.value) {
        notifyTyping(selectedChat.value.id.toString(), false)
      }
    }, 2000)
  } else if (!newVal && selectedChat.value) {
    // If input is cleared, stop typing indicator
    if (typingTimeout.value) {
      clearTimeout(typingTimeout.value)
    }
    notifyTyping(selectedChat.value.id.toString(), false)
  }
  
  // Adjust textarea height when input changes
  nextTick(() => {
    adjustTextareaHeight()
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

  return (hasText || hasAttachment) && hasChat && notSending && notUploading
})

// Computed property for textarea classes with dynamic corner radius and scrollbar
const textareaClasses = computed(() => {
  const baseClasses = [
    'flex-1',
    'resize-none',
    'border',
    'border-gray-300',
    'dark:border-zinc-700',
    'px-4',
    'py-2',
    'bg-white',
    'dark:bg-zinc-800',
    'text-gray-900',
    'dark:text-gray-100',
    'placeholder-gray-400',
    'dark:placeholder-gray-500',
    'focus:outline-none',
    'focus:ring-2',
    'focus:ring-green-400',
    'disabled:bg-gray-100',
    'dark:disabled:bg-zinc-700',
    'disabled:cursor-not-allowed',
    'min-h-[2.5rem]',
    'max-h-60'
  ]
  
  // Dynamic overflow based on height - only show scrollbar when at max height
  const showScrollbar = textareaHeight.value >= 235 // Close to max height (240px)
  baseClasses.push(showScrollbar ? 'overflow-y-auto' : 'overflow-y-hidden')
  
  // Dynamic corner radius based on height
  // If height is close to minimum (single line), use rounded-full, otherwise rounded-lg
  const isSingleLine = textareaHeight.value <= 45 // Slightly above min height to account for padding
  baseClasses.push(isSingleLine ? 'rounded-full' : 'rounded-lg')
  
  return baseClasses.join(' ')
})

// Normalized members for the selected chat (id, name, phone)
type Member = { id: string; name: string; phone?: string; avatar_url?: string }
const membersForChat = ref<Member[]>([])

// Fetch group chat members with contact names
const fetchGroupChatMembers = async (chatId: string) => {
  if (!selectedChat.value?.is_group) {
    // For direct chats, create members from participants
    const participants = selectedChat.value?.participants || []
    const normalizePhone = (val?: string | null) => {
      if (!val) return ''
      const withoutDomain = String(val).replace(/@.*$/, '')
      return withoutDomain.replace(/\D/g, '')
    }
    membersForChat.value = participants.map((p: any) => {
      if (typeof p === 'string') {
        const phoneDigits = normalizePhone(p)
        return {
          id: phoneDigits || String(p),
          name: selectedChat.value?.name || '',
          phone: p,
          avatar_url: selectedChat.value?.contact_info?.profile_picture_url
        }
      }
      return {
        id: p?.id?.toString?.() || String(p?.id ?? ''),
        name: String(p?.name ?? ''),
        phone: p?.phone ?? p?.phone_number,
        avatar_url: p?.avatar_url ?? p?.profile_picture_url ?? p?.contact_info?.profile_picture_url ?? undefined
      }
    })
    return
  }

  try {
    const response = await apiClient.get(`/chats/${chatId}/members`)
    membersForChat.value = response.data.data || []
  } catch (error) {
    console.error('Error fetching group chat members:', error)
    // Fallback to participants if API fails
    const participants = selectedChat.value?.participants || []
    const normalizePhone = (val?: string | null) => {
      if (!val) return ''
      const withoutDomain = String(val).replace(/@.*$/, '')
      return withoutDomain.replace(/\D/g, '')
    }
    membersForChat.value = participants.map((p: any) => {
      if (typeof p === 'string') {
        const phoneDigits = normalizePhone(p)
        return {
          id: phoneDigits || String(p),
          name: '',
          phone: p,
          avatar_url: undefined
        }
      }
      return {
        id: p?.id?.toString?.() || String(p?.id ?? ''),
        name: String(p?.name ?? ''),
        phone: p?.phone ?? p?.phone_number,
        avatar_url: p?.avatar_url ?? p?.profile_picture_url ?? p?.contact_info?.profile_picture_url ?? undefined
      }
    })
  }
}

// Derive current user for this chat: the member with phone === 'me'
const currentUserForChat = computed(() => {
  const me = membersForChat.value.find(m => m.phone === 'me')
  if (me) {
    return { id: me.id, name: 'You' }
  }
  // Fallback to auth currentUser
  return { id: currentUser.value.id, name: currentUser.value.name }
})

async function selectChat(chat: any) {
  selectedChat.value = chat
  // Fetch members for group chats
  if (chat?.id) {
    await fetchGroupChatMembers(chat.id)
  }
  // Hide sidebar on mobile when chat is selected
  showSidebarOnMobile.value = false
  
  // Load the last read message ID from database and set it in MessageList
  try {
    const response = await apiClient.get(`/chats/${chat.id}/last-read`)
    const lastReadId = response.data?.last_read_message_id
    
    // Set lastReadMessageId after a delay to ensure MessageList component is mounted
    setTimeout(() => {
      if (lastReadId && messageListRef.value && messageListRef.value.setLastReadMessageId) {
        messageListRef.value.setLastReadMessageId(lastReadId)
      }
    }, 100)
  } catch (error) {
    console.error('Failed to load last read message:', error)
  }
  
  // Mark chat as read when selected
  if (chat.unread_count && chat.unread_count > 0) {
    markChatAsRead(chat.id)
  }
  
  // Scroll to bottom after chat loads
  setTimeout(() => {
    if (messageListRef.value && messageListRef.value.scrollToBottom) {
      messageListRef.value.scrollToBottom()
    }
  }, 300)
}

const isMarkingChatAsRead = ref(false)
const lastReadAttemptedUnreadByChatId = ref<Record<string, number>>({})

const setChatUnreadCount = (chatId: string, unreadCount: number) => {
  const chat = chats.value.find(c => String(c.id) === String(chatId))
  if (chat) {
    chat.unread_count = unreadCount
  }
  if (selectedChat.value && String(selectedChat.value.id) === String(chatId)) {
    selectedChat.value.unread_count = unreadCount
  }
}

const markChatAsRead = async (chatId: string) => {
  if (!chatId) return
  if (isMarkingChatAsRead.value) return

  isMarkingChatAsRead.value = true
  try {
    await apiClient.post(`/chats/${chatId}/read`)
    setChatUnreadCount(chatId, 0)
  } catch (error) {
    console.error('Error marking chat as read:', error)
  } finally {
    isMarkingChatAsRead.value = false
  }
}

const handleChatMessagesRead = async (_messageIds: string[]) => {
  if (!selectedChat.value?.id) return

  // Ensure the chat-level unread count is cleared too (badge is based on chat.unread_count)
  setChatUnreadCount(String(selectedChat.value.id), 0)
  await markChatAsRead(String(selectedChat.value.id))
}

async function sendMessageHandler() {
  // If we're editing, submit the edit instead
  if (editingMessage.value) {
    await submitEdit()
    return
  }
  
  if ((!input.value && !attachmentPath.value) || !selectedChat.value || isSending.value) return
  
  // Store the message content before clearing
  const messageContent = input.value
  const messageAttachmentPath = attachmentPath.value
  const messageAttachmentMimetype = attachmentMimetype.value
  const messageAttachmentFile = attachmentFile.value
  const messageAttachmentPreviewUrl = attachmentPreviewUrl.value
  const messageAttachmentName = attachmentName.value
  const messageAttachmentSize = attachmentSize.value
  const messageReplyTo = replyToMessage.value
  const sentChatId = selectedChat.value.id
  
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
    
    // Get the WhatsApp JID (phone number) from chat participants
    // For direct chats, use the first participant (the other person's number)
    // Try to get the WhatsApp JID from multiple sources:
    // 1. First participant in the array
    // 2. WhatsApp ID from metadata
    // 3. Original name (the actual JID, not the formatted display name)
    // 4. Fall back to chat name
    const chatJid = selectedChat.value.metadata?.whatsapp_id 
                    || selectedChat.value.participants?.[0] 
                    || selectedChat.value.original_name
                    || selectedChat.value.name;
    
    let payload: any = {
      sender: 'me',
      chat: chatJid,
      type: messageType,
      content: messageContent || '',  // Ensure content is at least an empty string
    }
    
    // If we have an image, include the media path and mimetype
    // The image was already uploaded in onImageChange, so we just use the path
    if (messageAttachmentPath) {
      payload.media = messageAttachmentPath;
      payload.mimetype = messageAttachmentMimetype || undefined;
      payload.filename = messageAttachmentName || undefined;
      payload.size = typeof messageAttachmentSize === 'number' ? messageAttachmentSize : undefined;
    }
    
    // If replying to a message, include the reference
    if (messageReplyTo) {
      payload.reply_to_message_id = messageReplyTo.id;
    }
    
    // Clear input immediately to show responsiveness
    input.value = ''
    clearAttachmentState()
    clearReplyToMessage()
    
    // Stop typing indicator
    if (typingTimeout.value) {
      clearTimeout(typingTimeout.value)
    }
    notifyTyping(selectedChat.value.id.toString(), false)
    
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
    bumpChatActivity(sentChatId)
    
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
    
    const errorMessage = e?.response?.data?.message || e?.message || 'Fehler beim Senden der Nachricht';
    const validationErrors = e?.response?.data?.errors;
    if (validationErrors) {
      console.error('Validation errors:', validationErrors);
      alert('Validierungsfehler: ' + JSON.stringify(validationErrors));
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
  isUploadingAttachment.value = true

  try {
    const response = await uploadFile(file)
    attachmentPath.value = response.data.path
    attachmentMimetype.value = response.data.mimetype || file.type || null
    attachmentName.value = response.data.original_name || file.name
    attachmentSize.value = response.data.size ?? file.size
  } catch (error) {
    console.error('Attachment upload error:', error)
    alert('Datei-Upload fehlgeschlagen')
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

function selectCreatePoll() {
  showMenu.value = false
  showPollModal.value = true
  // Reset poll form
  pollQuestion.value = ''
  pollOptions.value = ['', '']
  allowMultipleSelection.value = false
}

function addPollOption() {
  if (pollOptions.value.length < 12) {
    pollOptions.value.push('')
  }
}

function removePollOption(index: number) {
  if (pollOptions.value.length > 2) {
    pollOptions.value.splice(index, 1)
  }
}

function closePollModal() {
  showPollModal.value = false
  pollQuestion.value = ''
  pollOptions.value = ['', '']
  allowMultipleSelection.value = false
  isCreatingPoll.value = false
}

async function createPoll() {
  if (!selectedChat.value || !pollQuestion.value.trim() || pollOptions.value.length < 2) {
    return
  }

  // Validate that all options have content
  const validOptions = pollOptions.value.filter(opt => opt.trim())
  if (validOptions.length < 2) {
    alert('Bitte geben Sie mindestens 2 gültige Antwortoptionen ein.')
    return
  }

  isCreatingPoll.value = true

  try {
    const pollData = {
      name: pollQuestion.value.trim(),
      options: validOptions.map(option => ({
        optionName: option.trim()
      })),
      pollType: 'POLL',
      pollContentType: 'TEXT',
      selectableOptionsCount: allowMultipleSelection.value ? 0 : 1
    }

    // Create a readable content representation for the message
    const content = `📊 **${pollData.name}**\n\n` + 
      pollData.options.map((option, index) => `${index + 1}. ${option.optionName}`).join('\n')

    // Get the WhatsApp JID (phone number) from chat participants
    // Same logic as sendMessageHandler
    const chatJid = selectedChat.value.metadata?.whatsapp_id 
                    || selectedChat.value.participants?.[0] 
                    || selectedChat.value.original_name
                    || selectedChat.value.name;

    const pollChatId = selectedChat.value.id
    await sendMessage({
      sender: 'me',
      chat: chatJid,
      type: 'poll',
      content: content,
      pollData: pollData
    })
    bumpChatActivity(pollChatId)

    // Close the modal and reset form
    closePollModal()
    
    // Clear the input
    input.value = ''
    
  } catch (error) {
    console.error('Error creating poll:', error)
    alert('Fehler beim Erstellen der Umfrage. Bitte versuchen Sie es erneut.')
  } finally {
    isCreatingPoll.value = false
  }
}

const handleLogout = async () => {
  isLoggingOut.value = true
  
  // Add a small delay for the animation to be visible
  await new Promise(resolve => setTimeout(resolve, 800))
  
  const authStore = useAuthStore()
  authStore.logout()
}

const startNewChat = () => {
  showNewChatModal.value = true
}

const closeNewChatModal = () => {
  showNewChatModal.value = false
  newChatPhone.value = ''
  isCreatingChat.value = false
}

const createNewChat = async () => {
  if (!newChatPhone.value.trim()) return
  
  // Clean the phone number (remove spaces, dashes, etc.)
  const cleanedNumber = newChatPhone.value.trim().replace(/[\s\-\(\)]/g, '')
  
  // Validate phone number (should contain only digits and optionally start with +)
  if (!/^\+?\d+$/.test(cleanedNumber)) {
    alert('Ungültige Telefonnummer. Bitte nur Ziffern eingeben (optional mit + am Anfang).')
    return
  }
  
  // Remove + if present for the JID format
  const numberWithoutPlus = cleanedNumber.replace(/^\+/, '')
  
  // Create WhatsApp JID format
  const whatsappJid = `${numberWithoutPlus}@s.whatsapp.net`
  
  isCreatingChat.value = true
  
  try {
    // Create or find the chat
    await apiClient.post('/chats', {
      name: whatsappJid, // Will be auto-formatted to +number on display
      participants: [whatsappJid],
      is_group: false
    })
    
    // Refresh chats to show the new chat
    const chatsResponse = await fetchChats()
    if (chatsResponse && chatsResponse.data && chatsResponse.data.data) {
      chats.value = chatsResponse.data.data
      
      // Find and select the newly created chat
      const newChat = chats.value.find(c => 
        c.metadata?.whatsapp_id === whatsappJid || 
        c.original_name === whatsappJid
      )
      if (newChat) {
        selectChat(newChat)
      }
    }
    
    // Close the modal
    closeNewChatModal()
  } catch (error: any) {
    console.error('Error creating new chat:', error)
    alert(error?.response?.data?.message || 'Fehler beim Erstellen des Chats')
  } finally {
    isCreatingChat.value = false
  }
}

const approveChat = async (chat: any) => {
  try {
    await apiClient.post(`/chats/${chat.id}/approve`)
    
    // Refresh chats to update the list
    const response = await fetchChats()
    if (response && response.data && response.data.data) {
      chats.value = response.data.data
      
      // Select the approved chat
      const approvedChat = chats.value.find(c => c.id === chat.id)
      if (approvedChat) {
        selectChat(approvedChat)
      }
    }
  } catch (e: any) {
    console.error('Error approving chat:', e)
    alert(e?.response?.data?.message || 'Fehler beim Annehmen des Chats')
  }
}

const rejectChat = async (chat: any) => {
  try {
    await apiClient.post(`/chats/${chat.id}/reject`)
    
    // Remove the chat from the list
    chats.value = chats.value.filter(c => c.id !== chat.id)
    
    // If the rejected chat was selected, clear the selection
    if (selectedChat.value && selectedChat.value.id === chat.id) {
      selectedChat.value = null
    }
  } catch (e: any) {
    console.error('Error rejecting chat:', e)
    alert(e?.response?.data?.message || 'Fehler beim Ablehnen des Chats')
  }
}

const confirmDeleteChat = async (chat: any) => {
  if (!confirm(`Möchten Sie den Chat mit "${getChatDisplayName(chat)}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`)) {
    return
  }
  
  try {
    await deleteChat(chat.id)
    
    // Remove the chat from the list
    chats.value = chats.value.filter(c => c.id !== chat.id)
    
    // If the deleted chat was selected, clear the selection
    if (selectedChat.value && selectedChat.value.id === chat.id) {
      selectedChat.value = null
      showSidebarOnMobile.value = true
    }
  } catch (e: any) {
    console.error('Error deleting chat:', e)
    alert(e?.response?.data?.message || 'Fehler beim Löschen des Chats')
  }
}

const handleReplyToMessage = (message: any) => {
  replyToMessage.value = message
  // Focus the input field
  nextTick(() => {
    messageInput.value?.focus()
  })
}

const clearReplyToMessage = () => {
  replyToMessage.value = null
}

const replySenderName = computed(() => {
  if (!replyToMessage.value) return ''
  
  const msg = replyToMessage.value
  
  // Check if this is the current user's message
  if (msg.sender_id?.toString() === currentUserForChat.value?.id?.toString()) {
    return 'Dir selbst'
  }
  
  // For group chats, try to find the sender from members
  if (selectedChat.value?.is_group && msg.sender_id) {
    const member = membersForChat.value.find(m => m.id === msg.sender_id?.toString())
    if (member?.name) return member.name
  }
  
  // Try sender_name from the message object
  if (msg.sender_name && msg.sender_name !== 'WhatsApp User') {
    return msg.sender_name
  }
  
  // For single chats, use the chat name
  if (!selectedChat.value?.is_group && selectedChat.value?.name) {
    return selectedChat.value.name
  }
  
  // Last resort: clean up the sender phone number
  if (msg.sender) {
    const cleaned = msg.sender.replace(/@s\.whatsapp\.net$/, '').replace(/@g\.us$/, '')
    return cleaned
  }
  
  return 'Unbekannt'
})

const handleEditMessage = (message: any) => {
  // Set editing mode
  editingMessage.value = message
  input.value = message.content
  
  // Focus the input field
  nextTick(() => {
    messageInput.value?.focus()
  })
}

const cancelEdit = () => {
  editingMessage.value = null
  input.value = ''
}

const submitEdit = async () => {
  if (!editingMessage.value || !input.value.trim()) {
    return
  }
  
  // Keep leading indentation, drop trailing whitespace
  const newContent = input.value.replace(/\s+$/, '')
  const messageId = editingMessage.value.id
  
  if (newContent === editingMessage.value.content) {
    // No changes made
    cancelEdit()
    return
  }
  
  try {
    await apiClient.put(`/messages/${messageId}`, {
      content: newContent
    })
    
    cancelEdit()
  } catch (error) {
    console.error('Failed to edit message:', error)
    alert('Fehler beim Bearbeiten der Nachricht')
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

// Set up polling interval variable at component level
let pollInterval: number | null = null
let isUnmounted = false

// Clean up interval when component unmounts
onUnmounted(() => {
  isUnmounted = true
  if (pollInterval) {
    clearInterval(pollInterval)
  }

  try {
    window.removeEventListener('resize', updateViewportHeightVar)
    window.removeEventListener('orientationchange', updateViewportHeightVar)
  } catch {}
})

onMounted(async () => {
  const isAuthenticated = await checkAuthAndRedirect()
  
  if (!isAuthenticated) {
    return
  }
  
  loadingChats.value = true
  errorChats.value = null
  try {
    // Fetch both chats and contacts
    const [chatsResponse] = await Promise.all([
      fetchChats(),
      fetchContacts()
    ])
    
    if (chatsResponse && chatsResponse.data && chatsResponse.data.data) {
      chats.value = chatsResponse.data.data
    } else {
      console.error('Invalid response format:', chatsResponse)
      errorChats.value = 'Ungültiges Antwortformat vom Server'
    }
  } catch (e: any) {
    console.error('Error fetching chats:', e)
    
    if (e.response?.status === 401) {
      errorChats.value = 'Sitzung abgelaufen. Bitte erneut anmelden.'
      const authStore = useAuthStore()
      authStore.logout()
    } else {
      errorChats.value = e?.message || 'Fehler beim Laden der Chats.'
    }
  } finally {
    loadingChats.value = false
  }
  
  // Component may have been left while the initial fetch was running
  if (isUnmounted) return

  // Set up periodic polling for new chats (every 5 seconds)
  pollInterval = window.setInterval(async () => {
    try {
      const response = await fetchChats()
      if (response && response.data && response.data.data) {
        const newChats = response.data.data
        
        // Check if there are any new pending chats
        const currentPendingCount = pendingChats.value.length
        const newPendingCount = newChats.filter((c: any) => c.pending_approval).length
        
        // If a chat is currently open, we don't want it to appear as unread in the list.
        // The backend increments unread_count for all incoming messages, so we must actively
        // clear it while the chat is open.
        if (selectedChat.value?.id != null) {
          const openChatId = String(selectedChat.value.id)
          const openChat = newChats.find((c: any) => String(c.id) === openChatId)

          if (openChat && openChat.unread_count && openChat.unread_count > 0) {
            const serverUnreadCount = Number(openChat.unread_count) || 0

            // Optimistically clear in the polling response to avoid badge persistence
            openChat.unread_count = 0

            // Avoid spamming the endpoint every poll for the same unread_count value
            const lastAttempted = lastReadAttemptedUnreadByChatId.value[openChatId]
            if (lastAttempted !== serverUnreadCount) {
              lastReadAttemptedUnreadByChatId.value[openChatId] = serverUnreadCount
              markChatAsRead(openChatId)
            }
          }
        }

        // Update the chats list
        chats.value = newChats
        syncSelectedChat(newChats)
      }
    } catch (error) {
      console.error('Error polling for new chats:', error)
    }
  }, 5000) // Poll every 5 seconds
})

// --- WhatsApp formatting in the composer ---
type FormatAction = { inline?: InlineFormat; line?: LineFormat; indent?: 1 | -1 }
type FormatButton = FormatAction & { key: string; label: string; title: string; cls?: string }

const formatButtons: FormatButton[] = [
  { key: 'bold', label: 'B', title: 'Fett (Strg+B)', cls: 'font-bold', inline: 'bold' },
  { key: 'italic', label: 'I', title: 'Kursiv (Strg+I)', cls: 'italic font-serif', inline: 'italic' },
  { key: 'strike', label: 'S', title: 'Durchgestrichen (Strg+Umschalt+X)', cls: 'line-through', inline: 'strike' },
  { key: 'code', label: '</>', title: 'Inline-Code', cls: 'font-mono text-xs', inline: 'code' },
  { key: 'mono', label: '```', title: 'Monospace-Block (Strg+Umschalt+M)', cls: 'font-mono text-xs', inline: 'mono' },
  { key: 'quote', label: '❝', title: 'Zitat', cls: 'text-base', line: 'quote' },
  { key: 'bullet', label: '•', title: 'Aufzählung', cls: 'text-base', line: 'bullet' },
  { key: 'numbered', label: '1.', title: 'Nummerierte Liste', line: 'numbered' },
  { key: 'outdent', label: '⇤', title: 'Ausrücken (Umschalt+Tab)', cls: 'text-base', indent: -1 },
  { key: 'indent', label: '⇥', title: 'Einrücken (Tab)', cls: 'text-base', indent: 1 },
]

const FORMAT_BAR_STORAGE_KEY = 'composer.showFormatBar'
const showFormatBar = ref(false)
try {
  showFormatBar.value = localStorage.getItem(FORMAT_BAR_STORAGE_KEY) === '1'
} catch {}

const toggleFormatBar = () => {
  showFormatBar.value = !showFormatBar.value
  try {
    localStorage.setItem(FORMAT_BAR_STORAGE_KEY, showFormatBar.value ? '1' : '0')
  } catch {}
  nextTick(() => messageInput.value?.focus())
}

// Rendered preview, only when the text actually contains formatting
const composerPreview = computed(() => (hasWhatsAppFormatting(input.value) ? renderWhatsAppText(input.value) : ''))

const applyFormat = async (action: FormatAction) => {
  const textarea = messageInput.value
  if (!textarea || !selectedChat.value) return
  const start = textarea.selectionStart ?? input.value.length
  const end = textarea.selectionEnd ?? start
  const result = action.inline
    ? toggleInlineFormat(input.value, start, end, action.inline)
    : action.line
      ? toggleLinePrefix(input.value, start, end, action.line)
      : action.indent
        ? shiftIndent(input.value, start, end, action.indent)
        : null
  if (!result) return
  input.value = result.text
  await nextTick()
  textarea.focus()
  textarea.setSelectionRange(result.selStart, result.selEnd)
  adjustTextareaHeight()
}

// Enter sends, Shift+Enter breaks the line; WhatsApp Web shortcuts for formatting
const onComposerKeydown = (e: KeyboardEvent) => {
  if (e.isComposing) return
  if (e.key === 'Enter' && !e.ctrlKey && !e.altKey && !e.metaKey) {
    e.preventDefault()
    // Shift+Enter: new line that continues lists / quotes / indentation
    if (e.shiftKey) insertLineBreak()
    else sendMessageHandler()
    return
  }
  // Tab / Shift+Tab indent list items, indented lines and multi-line selections;
  // elsewhere Tab keeps moving focus as usual
  if (e.key === 'Tab' && !e.ctrlKey && !e.altKey && !e.metaKey) {
    const textarea = messageInput.value
    if (!textarea) return
    const start = textarea.selectionStart ?? 0
    const end = textarea.selectionEnd ?? start
    const lineStart = input.value.lastIndexOf('\n', start - 1) + 1
    const lineEndIdx = input.value.indexOf('\n', start)
    const line = parseLine(input.value.slice(lineStart, lineEndIdx === -1 ? input.value.length : lineEndIdx))
    const multiLine = input.value.slice(start, end).includes('\n')
    if (line.kind !== 'plain' || line.indent || multiLine) {
      e.preventDefault()
      applyFormat({ indent: e.shiftKey ? -1 : 1 })
    }
    return
  }
  if (!(e.ctrlKey || e.metaKey) || e.altKey) return
  const key = e.key.toLowerCase()
  let inline: InlineFormat | null = null
  if (!e.shiftKey && key === 'b') inline = 'bold'
  else if (!e.shiftKey && key === 'i') inline = 'italic'
  else if (e.shiftKey && key === 'x') inline = 'strike'
  else if (e.shiftKey && key === 'm') inline = 'mono'
  if (!inline) return
  e.preventDefault()
  applyFormat({ inline })
}

// Insert a newline at the cursor (Shift+Enter and the <br> button for devices without it);
// continues numbered/bullet lists, quotes and indentation
const insertLineBreak = async () => {
  const textarea = messageInput.value
  if (!textarea) return
  const start = textarea.selectionStart ?? input.value.length
  const end = textarea.selectionEnd ?? start
  const result = insertNewline(input.value, start, end)
  input.value = result.text
  await nextTick()
  textarea.focus()
  textarea.setSelectionRange(result.selStart, result.selEnd)
  adjustTextareaHeight()
}

// Method to adjust textarea height based on content
const adjustTextareaHeight = () => {
  const textarea = messageInput.value
  if (textarea) {
    // Store the old height to detect changes
    const oldHeight = textareaHeight.value
    
    // Reset height to auto to get the correct scrollHeight
    textarea.style.height = 'auto'
    // Set height to scrollHeight, but cap at max-height (15rem = 240px, which is about 10 lines)
    const maxHeight = 240 // 15rem in pixels
    const scrollHeight = textarea.scrollHeight
    const newHeight = Math.min(scrollHeight, maxHeight)
    textarea.style.height = newHeight + 'px'
    
    // Update the reactive height for dynamic styling
    textareaHeight.value = newHeight
    
    // If the textarea expanded and the user is at the bottom, scroll down to keep messages visible
    if (newHeight > oldHeight && messageListRef.value) {
      // Check if user is scrolled to bottom using the exposed ref
      const isScrolledToBottomRef = messageListRef.value.isScrolledToBottom
      
      // The ref is auto-unwrapped when accessed from template ref, so it's already a boolean
      const isAtBottom = typeof isScrolledToBottomRef === 'object' ? isScrolledToBottomRef.value : isScrolledToBottomRef
      
      if (isAtBottom) {
        // Small delay to ensure layout has updated
        nextTick(() => {
          messageListRef.value?.scrollToBottom?.({ behavior: 'smooth' })
        })
      }
    }
  }
}

onMounted(() => {
  updateViewportHeightVar()
  try {
    window.addEventListener('resize', updateViewportHeightVar)
    window.addEventListener('orientationchange', updateViewportHeightVar)
  } catch {}

  // Initial height adjustment
  nextTick(() => {
    adjustTextareaHeight()
  })
})
</script>

<style scoped>
.app-root {
  height: calc(var(--vh, 1vh) * 100);
  min-height: calc(var(--vh, 1vh) * 100);
}

/* Custom minimalistic scrollbar for the message input textarea */
textarea::-webkit-scrollbar {
  width: 6px;
}

textarea::-webkit-scrollbar-track {
  background: transparent;
}

textarea::-webkit-scrollbar-thumb {
  background: #d1d5db; /* Light grey */
  border-radius: 3px;
}

textarea::-webkit-scrollbar-thumb:hover {
  background: #9ca3af; /* Darker grey on hover */
}

/* Firefox scrollbar styling */
textarea {
  scrollbar-width: thin;
  scrollbar-color: #d1d5db transparent;
}

/* Force dark mode for Neue Nachrichten indicator */
:deep(.dark) .neue-nachrichten-indicator {
  background-color: #18181b !important; /* zinc-900 */
  color: #facc15 !important; /* yellow-400 */
}
</style>
