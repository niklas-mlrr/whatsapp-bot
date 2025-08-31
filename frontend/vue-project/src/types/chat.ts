// Using inline type to avoid circular dependencies
type User = {
  id: string;
  name: string;
  phone_number: string;
  avatar_url?: string;
  status?: string;
  last_seen_at?: string;
  is_online?: boolean;
  is_typing?: boolean;
  last_typing_time?: number;
  metadata?: Record<string, any>;
  created_at?: string;
  updated_at?: string;
  isSelected?: boolean;
};

export interface Chat {
  id: string;
  name: string;
  is_group: boolean;
  created_at: string;
  updated_at: string;
  last_message?: string;
  last_message_at?: string;
  unread_count: number;
  is_muted: boolean;
  is_archived: boolean;
  is_blocked: boolean;
  participants: User[];
  admin_ids?: string[];
  metadata?: Record<string, any>;
  avatar_url?: string;
  description?: string;
  
  // For UI state
  isTyping?: boolean;
  isSelected?: boolean;
  isOnline?: boolean;
  lastSeen?: string;
}
