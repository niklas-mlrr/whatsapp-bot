import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import apiClient from '@/services/api';

export interface User {
  id: number;
  name: string;
  created_at: string;
  updated_at: string;
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('token') || null);
  const user = ref<User | null>({
    id: 1,
    name: 'Admin',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString()
  });
  
  const isAuthenticated = computed(() => !!token.value);
  const router = useRouter();

  // Initialize API client with token if it exists
  if (token.value) {
    apiClient.defaults.headers.common['Authorization'] = `Bearer ${token.value}`;
  }

  // Set auth token and update axios headers
  const setToken = (newToken: string) => {
    token.value = newToken;
    localStorage.setItem('token', newToken);
    apiClient.defaults.headers.common['Authorization'] = `Bearer ${newToken}`;
  };

  // Simplified login with just password
  const login = async (password: string) => {
    try {
      const response = await apiClient.post('/login', { password });
      const { token: authToken } = response.data;
      setToken(authToken);
      return response.data;
    } catch (error) {
      console.error('Login failed:', error);
      throw error;
    }
  };

  const logout = () => {
    // Make API call to invalidate token if needed
    apiClient.post('/logout').finally(() => {
      // Clear auth state regardless of API call result
      token.value = null;
      localStorage.removeItem('token');
      delete apiClient.defaults.headers.common['Authorization'];
      
      // Redirect to login
      router.push('/login');
    });
  };

  // Check if the current token is still valid
  const checkAuth = async () => {
    if (!token.value) return false;

    try {
      // Since we're using a simple auth, just verify the token exists
      return true;
    } catch (error) {
      console.error('Auth check failed:', error);
      logout();
      return false;
    }
  };

  return {
    token,
    user,
    isAuthenticated,
    login,
    logout,
    checkAuth,
  };
});
