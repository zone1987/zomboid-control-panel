import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/** Vite's dev server 404s on the base path without its trailing slash. */
function redirectBareBasePath() {
  return {
    name: 'redirect-bare-base-path',
    configureServer(server: { middlewares: { use: (fn: unknown) => void } }) {
      server.middlewares.use((req: { url?: string }, res: { writeHead: (c: number, h: object) => void; end: () => void }, next: () => void) => {
        if (req.url === '/app') {
          res.writeHead(301, { Location: '/app/' })
          res.end()

          return
        }

        next()
      })
    },
  }
}

export default defineConfig({
  plugins: [react(), tailwindcss(), redirectBareBasePath()],
  resolve: {
    alias: {
      '@': new URL('./src', import.meta.url).pathname,
    },
  },
  // Served under /app/ so Apache keeps /api for the backend.
  base: '/app/',
  build: {
    // Written straight into the Symfony document root so Apache serves the
    // same bundle locally that the container image ships.
    outDir: '../backend/public/app',
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
