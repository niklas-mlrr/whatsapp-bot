<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { useAuthStore } from './stores/auth';
import { useWebSocket } from './services/websocket';
import { useRouter } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();
const { connect, disconnect } = useWebSocket();

// Initialize WebSocket connection when authenticated
const initializeWebSocket = () => {
  if (authStore.isAuthenticated) {
    connect().then(connected => {
      if (!connected) {
        console.warn('Failed to connect to WebSocket - app will continue without real-time updates');
        console.info('To enable real-time features, start the WebSocket server: npm run start:reverb');
      }
    }).catch(error => {
      console.warn('WebSocket connection failed:', error.message);
    });
  } else {
    disconnect();
  }
};

// Watch for authentication changes
watch(() => authStore.isAuthenticated, (isAuthenticated) => {
  if (isAuthenticated) {
    initializeWebSocket();
  } else {
    disconnect();
  }
}, { immediate: true });

// Clean up on unmount
onMounted(() => {
  return () => {
    disconnect();
  };
});
</script>

<template>
  <RouterView />
</template>

<style>
/* Global styles */
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* Add any other global styles here */
</style>
