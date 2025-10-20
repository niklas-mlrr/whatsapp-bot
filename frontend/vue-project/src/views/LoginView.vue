<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-8 md:py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-6 md:space-y-8">
      <div>
        <h2 class="mt-4 md:mt-6 text-center text-2xl md:text-3xl font-extrabold text-gray-900">
          - Lukas WhatsApp -
          <br>
          Anmelden
        </h2>
      </div>
      <form class="mt-8 space-y-6" @submit.prevent="handleLogin">
        <div class="rounded-md shadow-sm -space-y-px">
          <div>
            <label for="password" class="sr-only">Passwort</label>
            <input
              id="password"
              v-model="password"
              name="password"
              type="password"
              autocomplete="current-password"
              required
              class="appearance-none rounded relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm"
              placeholder="Passwort eingeben"
            />
          </div>
        </div>

        <div>
          <button
            type="submit"
            :disabled="loading"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
              <LockClosedIcon v-if="!loading" class="h-5 w-5 text-blue-500 group-hover:text-blue-400" />
              <svg v-else class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </span>
            {{ loading ? 'Anmeldung läuft...' : 'Anmelden' }}
          </button>
        </div>
        
        <p v-if="error" class="mt-2 text-sm text-red-600 text-center">
          {{ error }}
        </p>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { LockClosedIcon } from '@heroicons/vue/20/solid';

const authStore = useAuthStore();
const router = useRouter();

const password = ref('');
const loading = ref(false);
const error = ref('');

const handleLogin = async () => {
  if (!password.value) {
    error.value = 'Bitte geben Sie Ihr Passwort ein';
    return;
  }

  loading.value = true;
  error.value = '';

  try {
    await authStore.login(password.value);
    // Redirect to the messages page after successful login
    router.push('/messages');
  } catch (err: any) {
    console.error('Login error:', err);
    error.value = err.response?.data?.message || 'Anmeldung fehlgeschlagen. Bitte versuchen Sie es erneut.';
  } finally {
    loading.value = false;
  }
};
</script>
