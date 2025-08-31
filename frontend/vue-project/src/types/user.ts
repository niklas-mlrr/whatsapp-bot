export interface User {
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
  
  // For UI state
  isSelected?: boolean;
}
