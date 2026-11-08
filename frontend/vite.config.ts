/// <reference types="vitest/config" />
import { availableParallelism } from 'node:os'
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
    // Host names Caddy forwards besides localhost: `php-e2e` is the in-network origin
    // the Playwright container uses (`php` is kept for tools that reach the dev app that way).
    allowedHosts: ['php', 'php-e2e'],
    // HMR needs no extra settings: the client connects back to the page origin
    // (http://localhost:8080 or http://php), and Caddy proxies the WebSocket.
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
    // Keeps a 5s asyncUtilTimeout wait (src/test/setup.ts) under the per-test timeout.
    testTimeout: 20000,
    // Running out of memory froze every worker at once, and each then failed the test it was on
    // at testTimeout (#366). Four workers use about 1.4 GiB less than seven for about 12% more wall
    // time. A runner with fewer cores keeps Vitest's default of one worker per core minus one.
    maxWorkers: Math.min(4, Math.max(1, availableParallelism() - 1)),
  },
})
