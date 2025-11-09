export interface Message {
  id: string;
  chat_id: string;
  sender_id: string;
  sender_phone?: string;
  recipient_phone?: string;
  content: string;
  type: 'text' | 'image' | 'video' | 'audio' | 'document' | 'location' | 'contact' | 'sticker' | 'system';
  status: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  created_at: string;
  updated_at: string;
  read_at?: string; // Timestamp when message was read
  read_by?: string[];
  isSending?: boolean;
  temp_id?: string;
  media_url?: string;
  media_type?: string;
  media_name?: string;
  media_size?: number;
  media_duration?: number;
  latitude?: number;
  longitude?: number;
  name?: string;
  phone_number?: string;
  quoted_message_id?: string;
  quoted_message?: Message;
  reactions?: {
    [emoji: string]: string[]; // user IDs who reacted with this emoji
  };
  metadata?: Record<string, any>;
}
