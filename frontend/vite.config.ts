import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': new URL('./src', import.meta.url).pathname,
    },
  },
  // Served under /app/ so Apache keeps /api for the backend.
  base: '/app/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // ddev terminates TLS in front of the container.
    allowedHosts: ['.ddev.site'],
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:80',
        changeOrigin: false,
      },
    },
  },
})
