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
}) =>
  apiClient.post('/messages', data);

export const uploadImage = (file: File) => {
  const formData = new FormData();
  formData.append('file', file);
  return apiClient.post<{ path: string; url: string }>('/upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};