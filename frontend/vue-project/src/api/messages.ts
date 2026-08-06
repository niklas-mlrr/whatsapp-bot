import apiClient from '@/services/api';
export interface WhatsAppMessage {
  id: number;
  sender: string;
  chat: string;
  type: string;
  content: string;
  sending_time: string;
  created_at: string;
}

export interface PaginationLinks {
  first: string;
  last: string;
  prev: string | null;
  next: string | null;
}

export interface PaginationMeta {
  current_page: number;
  from: number;
  last_page: number;
  per_page: number;
  to: number;
  total: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  links: PaginationLinks;
  meta: PaginationMeta;
}

export interface PollOption {
  optionName: string;
}

export interface PollData {
  name: string;
  options: PollOption[] | string[];
  selectableOptionsCount?: number;
  pollType?: string;
  pollContentType?: string;
}

export interface SendMessageParams {
  sender?: string;
  chat?: string;
  type?: string;
  content?: string;
  media?: string;
  mimetype?: string;
  sending_time?: string;
  filename?: string;
  size?: number;
  pollData?: PollData;
  quoted_message_whatsapp_id?: string;
  quoted_message_content?: string;
  quoted_message_from_me?: boolean;
}

export const fetchMessages = (params: Record<string, string | number | undefined>) =>
  apiClient.get<PaginatedResponse<WhatsAppMessage>>('/messages', { params });

export const fetchChats = () =>
  apiClient.get<{ data: import('@/types/chat').Chat[] }>('/chats');

export const sendMessage = (data: SendMessageParams) =>
  apiClient.post('/messages', data);

export const uploadFile = (file: File) => {
  const formData = new FormData();
  formData.append('file', file);
  return apiClient.post<{ path: string; url: string; mimetype: string; original_name: string; size: number }>('/upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

export const deleteChat = (chatId: string | number) =>
  apiClient.delete(`/chats/${chatId}`);