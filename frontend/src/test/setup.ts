import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach } from 'vitest'
import * as React from 'react'

// The full parallel run is slower than the 1s default for findBy*/waitFor; flakes forced reruns.
configure({ asyncUtilTimeout: 5000 })

afterEach(() => {
  cleanup()
})

// jsdom lacks what Radix Select calls when it opens.
const polyfills = {
  hasPointerCapture: () => false,
  setPointerCapture: () => undefined,
  releasePointerCapture: () => undefined,
  scrollIntoView: () => undefined,
}
for (const [name, value] of Object.entries(polyfills)) {
  if (!(name in Element.prototype)) {
    Object.defineProperty(Element.prototype, name, { value, configurable: true, writable: true })
  }
}

// React's dev jsx() captures an Error stack for each element until this counter reaches 10,000
// per render, which was about 17% of the Studio editor test time. Pinning it high skips the
// capture; dev warnings then show less precise owner stacks. A no-op if React drops the field.
const internals = (React as unknown as Record<string, unknown>)
  .__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE
if (
  typeof internals === 'object' &&
  internals !== null &&
  'recentlyCreatedOwnerStacks' in internals
) {
  Object.defineProperty(internals, 'recentlyCreatedOwnerStacks', {
    get: () => 1e9,
    set: () => undefined,
    configurable: true,
  })
}
