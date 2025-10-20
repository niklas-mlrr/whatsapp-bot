import { defineStore } from 'pinia';
import { ref, watch } from 'vue';

export const useThemeStore = defineStore('theme', () => {
  // Initialize from localStorage or default to light mode
  const isDarkMode = ref<boolean>(localStorage.getItem('darkMode') === 'true');

  // Apply theme class to document
  const applyTheme = (dark: boolean) => {
    if (dark) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  };

  // Apply initial theme
  applyTheme(isDarkMode.value);

  // Watch for changes and persist to localStorage
  watch(isDarkMode, (newValue) => {
    localStorage.setItem('darkMode', String(newValue));
    applyTheme(newValue);
  });

  // Toggle dark mode
  const toggleDarkMode = () => {
    isDarkMode.value = !isDarkMode.value;
  };

  return {
    isDarkMode,
    toggleDarkMode,
  };
});
