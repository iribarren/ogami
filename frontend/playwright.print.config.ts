import { defineConfig } from '@playwright/test'

/**
 * Prints the manual PDFs from the build in `dist-print/` (`make manual-pdf`). It has its own
 * config so `make e2e` never runs it: it needs no stack, only the build and Chromium.
 */
export default defineConfig({
  testDir: './print',
  timeout: 120_000,
  reporter: [['list']],
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
})
