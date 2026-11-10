import { fileURLToPath, URL } from 'node:url'

import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

/**
 * The print entry (`print.html`): the Paper edition of the manual and the card sheets, rendered for
 * Chromium to print (`make manual-pdf`). It is a separate build, not reachable from the SPA and
 * with no route, so none of it reaches `dist/`. Relative URLs let a test serve it from any origin.
 */
export default defineConfig({
  base: './',
  plugins: [react()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: 'dist-print',
    emptyOutDir: true,
    rollupOptions: { input: fileURLToPath(new URL('./print.html', import.meta.url)) },
  },
})
