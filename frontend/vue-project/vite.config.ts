import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
// import vueDevTools from 'vite-plugin-vue-devtools' // <-- comment out

// https://vite.dev/config/
export default defineConfig(({ command, mode }) => ({
  define: {
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV),
  },
  plugins: [
    vue(),
    // vueDevTools(), 
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8001',
        changeOrigin: true,
        secure: false
      },
      '/storage': {
        target: 'http://localhost:8001',
        changeOrigin: true,
        secure: false
      },
      '/broadcasting': {
        target: 'http://localhost:8001',
        ws: true,
        changeOrigin: true,
        secure: false
      },
      '/ws': {
        target: 'ws://localhost:8081',
        ws: true,
        changeOrigin: true,
        secure: false
      }
    }
  }
}))
