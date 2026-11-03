import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests against the running compose stack (ADR 0009).
 * Run with `make e2e`: the `playwright` compose service (profile `e2e`) runs
 * them in the official Playwright image, inside the compose network, where
 * Caddy serves the SPA and the API at http://php.
 */
export default defineConfig({
  testDir: './e2e',
  // The default 30 s is too tight: the specs drive a Vite dev server, and with the workers running
  // side by side several of them take around that long without anything being wrong.
  timeout: 60_000,
  // Same reason: after a reload the dev server and the API can take the whole default 5 s to answer.
  expect: { timeout: 10_000 },
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
