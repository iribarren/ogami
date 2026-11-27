import { tmpdir } from 'node:os'

import { afterEach, describe, expect, it } from 'vitest'

import { loadPreset } from './testSupport'

describe('loadPreset', () => {
  const start = process.cwd()

  afterEach(() => {
    process.chdir(start)
  })

  it('finds the preset files whatever the working directory is', () => {
    process.chdir(tmpdir())
    const preset = loadPreset('dogwatch') as { flows: { key: string }[] }
    expect(preset.flows.map((flow) => flow.key)).toContain('cold-berth')
  })
})
