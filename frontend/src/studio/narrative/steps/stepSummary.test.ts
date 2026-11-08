import { stepsToDrafts } from './stepDraft'
import { stepSummary } from './stepSummary'

const summary = (item: unknown) => {
  const [step] = stepsToDrafts([item])
  if (!step) {
    throw new Error('No step.')
  }
  return stepSummary(step)
}

describe('the step summary', () => {
  it('is empty for a step with nothing to count and no next', () => {
    expect(summary({ key: 'a', kind: 'prompt', title: 'A', prompt: 'Hi.' })).toBe('')
  })

  it('names the next step and counts the step own effects', () => {
    const effects = [{ kind: 'sceneTitle', title: 'One' }]
    expect(summary({ key: 'a', kind: 'prompt', next: 'b', effects })).toBe('next: b · 1 effect')
    expect(
      summary({
        key: 'a',
        kind: 'prompt',
        effects: [...effects, { kind: 'sceneTitle', title: 'Two' }],
      }),
    ).toBe('2 effects')
  })

  it('counts the options of a choice, singular and plural', () => {
    const option = (key: string) => ({ key, label: key })
    expect(summary({ key: 'c', kind: 'choice', options: [option('x')] })).toBe('1 option')
    expect(summary({ key: 'c', kind: 'choice', options: [option('x'), option('y')] })).toBe(
      '2 options',
    )
  })

  it('counts the branches of a table and the bands of a roll', () => {
    expect(
      summary({
        key: 't',
        kind: 'table',
        table: 'x',
        branches: [{ entry: 'a' }, { entry: 'b' }],
      }),
    ).toBe('2 branches')
    expect(
      summary({ key: 'r', kind: 'roll', dice: '1d6', bands: [{ upTo: 3 }, { label: 'rest' }] }),
    ).toBe('2 bands')
  })

  it('counts the outcomes of a check', () => {
    expect(
      summary({ key: 'k', kind: 'check', check: 'x', next: 'z', outcomes: [{ band: 'hit' }] }),
    ).toBe('next: z · 1 outcome')
  })

  it('omits a count of zero', () => {
    expect(summary({ key: 't', kind: 'table', table: 'x', branches: [] })).toBe('')
  })

  it('has nothing to say about a stored step', () => {
    expect(summary({ key: 's', kind: 'mystery', next: 'b' })).toBe('')
  })

  it('never counts the characters of options or outcomes it cannot read', () => {
    expect(summary({ key: 'c', kind: 'choice', options: 'none' })).not.toMatch(/option/)
    expect(summary({ key: 'k', kind: 'check', check: 'x', outcomes: 'none' })).not.toMatch(
      /outcome/,
    )
  })
})
