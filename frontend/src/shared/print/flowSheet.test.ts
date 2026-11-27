import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { readRelease } from './release'
import { buildFlowSheet } from './flowSheet'
import { miniRelease, step } from './testRelease'

const presets = resolve(import.meta.dirname, '../../../../backend/presets')

function release(change: (raw: ReturnType<typeof miniRelease>) => void = () => undefined) {
  const raw = miniRelease()
  const phase = raw.flows[0]?.phases[0] as Record<string, unknown>
  phase.description = 'Draw and play.'
  phase.sceneClosing = [
    step('ask', 'prompt', { prompt: 'Look at {step:other} and the {fact:sin}.' }),
    step('other', 'prompt', { title: 'The other step', prompt: 'Say.' }),
  ]
  Object.assign(raw.flows[0] ?? {}, { introduction: 'You carry a saint.' })
  change(raw)
  return readRelease(raw)
}

describe('buildFlowSheet', () => {
  it('words each phase: its mode, how it picks scenes, with names, and its hooks with their steps', () => {
    const sheet = buildFlowSheet(release(), 'mini')

    expect(sheet).toMatchObject({
      name: 'Mini',
      description: 'A question?',
      introduction: 'You carry a saint.',
    })
    expect(sheet.phases.map((phase) => [phase.name, phase.mode, phase.selection])).toEqual([
      ['Road', 'Repeats', 'Each scene is drawn from The deck.'],
      ['End', 'Played once', 'Scenes in order: Scene scene.'],
    ])
    expect(sheet.phases[0]?.description).toBe('Draw and play.')
    expect(sheet.phases[0]?.hooks).toEqual([
      {
        label: 'Scene closing',
        lines: ['Title of ask. Look at The other step and the The Sin.', 'The other step. Say.'],
      },
    ])
  })

  it('words a player selection as a choice', () => {
    const sheet = buildFlowSheet(
      release((raw) => {
        Object.assign(raw.flows[0]?.phases[1] ?? {}, {
          selection: { rule: 'player', sceneTypes: ['scene'] },
        })
      }),
      'mini',
    )

    expect(sheet.phases[1]?.selection).toBe('You choose the scene from: Scene scene.')
  })

  it.each([
    ['a flow the release does not have', 'nope', () => undefined, /The release has no flow "nope"/],
    [
      'a Scene Type a phase names that the release lacks',
      'mini',
      (raw: ReturnType<typeof miniRelease>) =>
        Object.assign(raw.flows[0]?.phases[1]?.selection ?? {}, { sceneTypes: ['gone'] }),
      /Phase "end" of the flow "mini" names the Scene Type "gone"/,
    ],
    [
      'a table a phase draws from that the release lacks',
      'mini',
      (raw: ReturnType<typeof miniRelease>) =>
        Object.assign(raw.flows[0]?.phases[0]?.selection ?? {}, { table: 'gone' }),
      /Phase "road" of the flow "mini" draws from the table "gone"/,
    ],
    [
      'a {step:} placeholder that points nowhere',
      'mini',
      (raw: ReturnType<typeof miniRelease>) =>
        Object.assign(
          (raw.flows[0]?.phases[0] as Record<string, unknown>).sceneClosing as object[],
          {
            0: step('ask', 'prompt', { prompt: 'See {step:gone}.' }),
          },
        ),
      /road\.sceneClosing\.ask: \{step:gone\} is not a step of the same list/,
    ],
    [
      'a {fact:} placeholder that points nowhere',
      'mini',
      (raw: ReturnType<typeof miniRelease>) =>
        Object.assign(
          (raw.flows[0]?.phases[0] as Record<string, unknown>).sceneClosing as object[],
          {
            0: step('ask', 'prompt', { prompt: 'See {fact:gone}.' }),
          },
        ),
      /\{fact:gone\} is not a Fact slot of the release/,
    ],
  ])('says what is wrong with %s, and does not build it', (_name, flow, change, message) => {
    expect(() => buildFlowSheet(release(change), flow)).toThrow(message)
  })

  it('builds the sheet of every flow of every preset', () => {
    const files = readdirSync(presets).filter((name) => name.endsWith('.json'))

    expect(files.length).toBeGreaterThan(0)
    for (const file of files) {
      const preset = readRelease(JSON.parse(readFileSync(resolve(presets, file), 'utf8')))
      for (const flow of preset.flows) {
        const sheet = buildFlowSheet(preset, flow.key)

        expect(sheet.phases, `${file} ${flow.key}`).toHaveLength(flow.phases.length)
        expect(
          sheet.phases.every((phase) => phase.selection !== ''),
          file,
        ).toBe(true)
      }
    }
  })
})
