import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios';
import { useAuthStore } from '@/stores/auth';
import { API_CONFIG, API_ENDPOINTS } from '@/config/api';

// Create axios instance with base URL
const apiClient: AxiosInstance = axios.create({
  baseURL: API_CONFIG.BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true,
});

// Add request interceptor to include auth token
apiClient.interceptors.request.use(
  (config) => {
    // Get token from localStorage directly to avoid Pinia store context issues
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Add response interceptor to handle errors
apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;
    
    // If the error status is 401 and we have a token (meaning it's expired/invalid)
    if (error.response?.status === 401 && localStorage.getItem('token') && !originalRequest._retry) {
      originalRequest._retry = true;
      
      // For now, just log the user out since we don't have token refresh
      console.log('Token expired or invalid, logging out');
      // Clear token and redirect to login
      localStorage.removeItem('token');
      window.location.href = '/login';
      return Promise.reject(error);
    }
    
    return Promise.reject(error);
  }
);

// Auth API
export const authApi = {
  login: async (password: string) => {
    const response = await apiClient.post(API_ENDPOINTS.LOGIN, { password });
    return response.data;
  },
  
  logout: async () => {
    const response = await apiClient.post(API_ENDPOINTS.LOGOUT);
    return response.data;
  },
  
  getMe: async () => {
    const response = await apiClient.get(API_ENDPOINTS.ME);
    return response.data;
  },
};

// Messages API
export const messagesApi = {
  getChats: async () => {
    const response = await apiClient.get(API_ENDPOINTS.CHATS);
    return response.data;
  },
  
  getMessages: async (chatId: string, params?: any) => {
    const response = await apiClient.get(`${API_ENDPOINTS.MESSAGES}?chat_id=${chatId}`, { params });
    return response.data;
  },
  
  sendMessage: async (chatId: string, content: string, tempId?: string) => {
    const response = await apiClient.post(API_ENDPOINTS.MESSAGES, {
      chat_id: chatId,
      content,
      temp_id: tempId,
    });
    return response.data;
  },
  
  uploadFile: async (file: File, onUploadProgress?: (progressEvent: any) => void) => {
    const formData = new FormData();
    formData.append('file', file);
    
    const response = await apiClient.post(API_ENDPOINTS.UPLOAD, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      onUploadProgress,
    });
    
    return response.data;
  },
  
  markAsRead: async (messageIds: string[]) => {
    const response = await apiClient.post('/messages/mark-read', { message_ids: messageIds });
    return response.data;
  },
};

export default apiClient;
