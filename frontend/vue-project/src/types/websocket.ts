/**
 * WebSocket event type definitions
 */

export interface MessageEvent {
  id: string;
  chat_id: string;
  user_id: string;
  content: string;
  created_at: string;
  updated_at: string;
  status?: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
}

export interface TypingEvent {
  user_id: string;
  is_typing: boolean;
  chat_id: string;
}

export interface ReadReceiptEvent {
  message_id: string;
  user_id: string;
  chat_id: string;
}

export interface UserPresenceEvent {
  user_id: string;
  is_online: boolean;
  last_seen_at?: string;
}

export interface ChatEvent {
  chat_id: string;
  action: 'created' | 'archived' | 'muted' | 'cleared';
  data?: Record<string, unknown>;
}

export interface MessageStatusEvent {
  message_id: string;
  status: 'sent' | 'delivered' | 'read' | 'failed';
  timestamp: string;
}

export type WebSocketEventType =
  | 'message.sent'
  | 'message.read'
  | 'message.deleted'
  | 'message.edited'
  | 'message.reaction'
  | 'chat.created'
  | 'chat.archived'
  | 'chat.muted'
  | 'chat.cleared'
  | 'user.typing'
  | 'user.presence';