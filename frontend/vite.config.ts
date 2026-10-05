/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import { tanstackRouter } from '@tanstack/router-plugin/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    // Must run before the React plugin: it generates src/routeTree.gen.ts from src/routes/.
    tanstackRouter({ target: 'react', autoCodeSplitting: true }),
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    // Runs in the node container; Caddy (php service) proxies every non-API request here.
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Host names Caddy forwards besides localhost: `php` is the in-network origin
    // the Playwright container uses.
    allowedHosts: ['php'],
    // HMR needs no extra settings: the client connects back to the page origin
    // (http://localhost:8080 or http://php), and Caddy proxies the WebSocket.
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
  },
})
