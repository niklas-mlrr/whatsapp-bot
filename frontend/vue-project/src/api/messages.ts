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

export interface PaginatedResponse<T> {
  data: T[];
  links: any;
  meta: any;
}

export const fetchMessages = (params: Record<string, any>) =>
  apiClient.get<PaginatedResponse<WhatsAppMessage>>('/messages', { params });

export const fetchChats = () =>
  apiClient.get<{ data: any[] }>('/chats');

export const sendMessage = (data: {
  sender: string;
  chat: string;
  type: string;
  content?: string;
  media?: string;
  mimetype?: string;
  sending_time?: string;
  filename?: string;
  size?: number;
}) =>
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