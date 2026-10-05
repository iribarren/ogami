// @ts-check
import js from '@eslint/js'
import prettier from 'eslint-config-prettier'
import reactHooks from 'eslint-plugin-react-hooks'
import { reactRefresh } from 'eslint-plugin-react-refresh'
import { defineConfig, globalIgnores } from 'eslint/config'
import globals from 'globals'
import tseslint from 'typescript-eslint'

export default defineConfig(
  globalIgnores([
    'dist',
    'storybook-static',
    'playwright-report',
    'test-results',
    'src/routeTree.gen.ts',
    'src/shared/api/schema.d.ts',
  ]),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.strictTypeChecked,
      tseslint.configs.stylisticTypeChecked,
      reactHooks.configs.flat['recommended-latest'],
      reactRefresh.configs.vite(),
    ],
    languageOptions: {
      ecmaVersion: 2023,
      globals: globals.browser,
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },
  {
    // Route files export a `Route` object, shadcn/ui files export variants next to
    // components, and stories/tests are not hot-reloaded modules.
    files: ['src/routes/**', 'src/shared/ui/**', '**/*.stories.tsx', '**/*.test.tsx'],
    rules: { 'react-refresh/only-export-components': 'off' },
  },
  {
    files: ['*.js', '*.ts'],
    languageOptions: { globals: globals.node },
  },
  // Last: turns off every stylistic rule that Prettier owns.
  prettier,
)
