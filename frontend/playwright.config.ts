import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests against the running compose stack (ADR 0009).
 * Run with `make e2e`: the `playwright` compose service (profile `e2e`) runs
 * them in the official Playwright image, inside the compose network, where
 * Caddy serves the SPA and the API at http://php.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://php',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
