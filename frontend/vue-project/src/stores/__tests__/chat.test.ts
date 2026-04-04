import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useChatStore } from '../chat'

// Mock the API client
vi.mock('@/services/api', () => ({
  default: {
    get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
    post: vi.fn(() => Promise.resolve({ data: { data: {} } })),
  },
}))

describe('Chat Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  describe('initial state', () => {
    it('starts with empty chats array', () => {
      const store = useChatStore()
      expect(store.chats).toEqual([])
    })

    it('starts with null currentChatId', () => {
      const store = useChatStore()
      expect(store.currentChatId).toBeNull()
    })

    it('starts with empty messages object', () => {
      const store = useChatStore()
      expect(store.messages).toEqual({})
    })

    it('starts with loading false', () => {
      const store = useChatStore()
      expect(store.loading).toBe(false)
    })

    it('starts with null error', () => {
      const store = useChatStore()
      expect(store.error).toBeNull()
    })
  })

  describe('getters', () => {
    it('currentChat returns undefined when no chat selected', () => {
      const store = useChatStore()
      expect(store.currentChat).toBeUndefined()
    })

    it('currentMessages returns empty array when no chat selected', () => {
      const store = useChatStore()
      expect(store.currentMessages).toEqual([])
    })

    it('unreadCount returns 0 when no chats', () => {
      const store = useChatStore()
      expect(store.unreadCount).toBe(0)
    })

    it('filteredChats returns all chats when no search query', () => {
      const store = useChatStore()
      store.chats = [
        { id: '1', name: 'Test Chat', participants: [] },
      ]
      expect(store.filteredChats).toHaveLength(1)
    })
  })

  describe('actions', () => {
    it('setCurrentChat sets the current chat ID', () => {
      const store = useChatStore()
      store.setCurrentChat('chat-123')
      expect(store.currentChatId).toBe('chat-123')
    })

    it('clearError sets error to null', () => {
      const store = useChatStore()
      store.error = 'Some error'
      store.clearError()
      expect(store.error).toBeNull()
    })

    it('addMessage adds message to correct chat', () => {
      const store = useChatStore()
      store.messages['chat-123'] = []

      store.addMessage('chat-123', {
        id: 'msg-1',
        chat_id: 'chat-123',
        content: 'Hello',
        type: 'text',
        sender_id: 'user-1',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      })

      expect(store.messages['chat-123']).toHaveLength(1)
      expect(store.messages['chat-123'][0].content).toBe('Hello')
    })
  })
})