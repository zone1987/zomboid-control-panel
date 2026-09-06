import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

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
  plugins: [
    react(),
    tailwindcss(),
    redirectBareBasePath(),
    VitePWA({
      registerType: 'prompt',
      includeAssets: ['favicon.svg', 'apple-touch-icon.png'],
      manifest: {
        name: 'ZomboidControl',
        short_name: 'Zomboid',
        description: 'Control panel for Project Zomboid dedicated servers',
        start_url: '/app/',
        scope: '/app/',
        display: 'standalone',
        background_color: '#11130e',
        theme_color: '#11130e',
        icons: [
          { src: '/app/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/app/icon-512.png', sizes: '512x512', type: 'image/png' },
          {
            src: '/app/icon-maskable-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        // Network first for the API: an administration panel showing a
        // stale player list is worse than one that waits.
        navigateFallback: null,
        runtimeCaching: [
          {
            urlPattern: /\/api\//,
            handler: 'NetworkOnly',
          },
          {
            // Tiles never change and are the bulk of the traffic.
            urlPattern: /^https:\/\/tiles\.projectzomboidmap\.com\//,
            handler: 'CacheFirst',
            options: {
              cacheName: 'map-tiles',
              expiration: { maxEntries: 3000, maxAgeSeconds: 60 * 60 * 24 * 30 },
            },
          },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
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
    // Pages are split per route; what remains is React, the router and
    // i18n, which every screen needs anyway.
    chunkSizeWarningLimit: 600,
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
        // Server-sent events arrive as one long response. Without this
        // the dev proxy holds the whole thing until the connection
        // ends, so a live stream shows nothing until it is over --
        // which production, going straight through Apache, does not do.
        configure: (proxy) => {
          proxy.on('proxyRes', (proxyRes, req) => {
            if (req.url?.endsWith('/stream') === true) {
              delete proxyRes.headers['content-encoding']
              proxyRes.headers['cache-control'] = 'no-cache, no-transform'
            }
          })
        },
      },
    },
  },
})
