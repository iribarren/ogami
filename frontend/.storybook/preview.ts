import type { Decorator, Preview } from '@storybook/react-vite'

import '../src/index.css'

/** Slice 3 adds the curated campaign themes to this list. */
const THEMES = ['default']

const withColorModeAndTheme: Decorator = (Story, context) => {
  const root = document.documentElement
  const dark = context.globals.colorMode === 'dark'
  root.classList.toggle('dark', dark)
  root.style.colorScheme = dark ? 'dark' : 'light'

  const theme = String(context.globals.theme)
  if (theme === 'default') {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', theme)
  }
  return Story()
}

const preview: Preview = {
  decorators: [withColorModeAndTheme],
  globalTypes: {
    colorMode: {
      description: 'Color mode',
      toolbar: {
        title: 'Color mode',
        icon: 'circlehollow',
        items: [
          { value: 'light', title: 'Light' },
          { value: 'dark', title: 'Dark' },
        ],
        dynamicTitle: true,
      },
    },
    theme: {
      description: 'Theme',
      toolbar: {
        title: 'Theme',
        icon: 'paintbrush',
        items: THEMES.map((value) => ({ value, title: value })),
        dynamicTitle: true,
      },
    },
  },
  initialGlobals: { colorMode: 'light', theme: 'default' },
  parameters: {
    controls: {
      matchers: { color: /(background|color)$/i, date: /Date$/i },
    },
  },
}

export default preview
