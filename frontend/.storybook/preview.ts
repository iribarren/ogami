import type { Decorator, Preview } from '@storybook/react-vite'
import { createElement } from 'react'

import '../src/index.css'
import { StoryTheme } from '../src/shared/theme/StoryTheme'
import { CAMPAIGN_THEME_NAMES } from '../src/shared/theme/themes'

const THEMES = ['default', ...CAMPAIGN_THEME_NAMES]

// A curated theme fixes its own mode, so picking one overrides the Color mode toolbar, as in a campaign.
const withColorModeAndTheme: Decorator = (Story, context) =>
  createElement(StoryTheme, {
    colorMode: context.globals.colorMode === 'dark' ? 'dark' : 'light',
    theme: String(context.globals.theme),
    children: createElement(Story),
  })

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
