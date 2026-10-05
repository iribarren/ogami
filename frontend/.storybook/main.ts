import { defineMain } from '@storybook/react-vite/node'

export default defineMain({
  framework: '@storybook/react-vite',
  stories: ['../src/**/*.stories.@(ts|tsx)'],
  // Reuses vite.config.ts (React, Tailwind, the `@` alias).
  core: { disableTelemetry: true },
})
