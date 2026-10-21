import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach } from 'vitest'

// The full parallel run is slower than the 1s default for findBy*/waitFor; flakes forced reruns.
configure({ asyncUtilTimeout: 5000 })

afterEach(() => {
  cleanup()
})
