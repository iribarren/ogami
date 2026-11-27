import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { buildCampaignLog, TAG_LINES } from './campaignLog'
import { readRelease } from './release'
import { miniRelease } from './testRelease'

const presets = resolve(import.meta.dirname, '../../../../backend/presets')

describe('buildCampaignLog', () => {
  it('lists the Fact slots and the Tags with their hints, a Tag with its blank lines', () => {
    expect(buildCampaignLog(readRelease(miniRelease()))).toEqual({
      slots: [{ key: 'sin', label: 'The Sin', hint: 'What was done.' }],
      tags: [{ key: 'ally', label: 'Ally', hint: 'Someone on your side.' }],
      tagLines: TAG_LINES,
    })
    expect(TAG_LINES).toBe(4)
  })

  it('is still a log, with no entries, for a release without Fact slots and Tags', () => {
    const raw = miniRelease()
    Object.assign(raw, { factSlots: undefined, tags: undefined })

    expect(buildCampaignLog(readRelease(raw))).toEqual({ slots: [], tags: [], tagLines: TAG_LINES })
  })

  it('builds the log of every preset', () => {
    const files = readdirSync(presets).filter((name) => name.endsWith('.json'))

    expect(files.length).toBeGreaterThan(0)
    for (const file of files) {
      const preset = readRelease(JSON.parse(readFileSync(resolve(presets, file), 'utf8')))
      const log = buildCampaignLog(preset)

      expect(log.slots.length, file).toBe(preset.factSlots.length)
      expect(log.tags.length, file).toBe(preset.tags.length)
    }
  })
})
