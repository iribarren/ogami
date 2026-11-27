import { newEffect } from '../steps/effectDraft'
import { sceneEffect } from '../flows/flowMapCarriers'
import {
  carrierParam,
  effectParam,
  parseEffect,
  parseStep,
  pendingOf,
  stepParam,
  transitionSearch,
} from './sceneTypeParams'

describe('the Scene Type search params', () => {
  it('read a step as its list and its 0-based place, and nothing else', () => {
    expect(parseStep('play.1')).toEqual({ part: 'play', index: 1 })
    expect(parseStep('setup.0')).toEqual({ part: 'setup', index: 0 })
    for (const bad of [
      'play',
      'play.',
      'play.-1',
      'play.01',
      'play.1.2',
      'later.1',
      'play.x',
      7,
      null,
    ]) {
      expect(parseStep(bad)).toBeNull()
      expect(stepParam(bad)).toBeUndefined()
    }
    expect(stepParam('closing.3')).toBe('closing.3')
  })

  it('keep a carrier only when it is shaped like one of the carrier ids', () => {
    for (const good of [
      'step',
      'otherwise',
      'band:0',
      'option:12',
      'oracle:yes',
      'pick:found',
      'check:1',
    ]) {
      expect(carrierParam(good)).toBe(good)
    }
    for (const bad of ['', 'band', 'band:x', 'other', 'step:1', 'oracle:', 'band:1234', 4]) {
      expect(carrierParam(bad)).toBeUndefined()
    }
  })

  it('read an effect as endPhase, nextScene or switchSceneType with the Scene Type key', () => {
    expect(parseEffect('endPhase')).toEqual({ kind: 'endPhase' })
    expect(parseEffect('nextScene:ambush')).toEqual({ kind: 'nextScene', sceneType: 'ambush' })
    expect(parseEffect('switchSceneType:a:b')).toEqual({
      kind: 'switchSceneType',
      sceneType: 'a:b',
    })
    for (const bad of [
      'nextScene',
      'nextScene:',
      'nextScene:a b',
      'endPhase:x',
      'tracker:x',
      '',
      3,
    ]) {
      expect(parseEffect(bad)).toBeNull()
      expect(effectParam(bad)).toBeUndefined()
    }
    expect(effectParam('endPhase')).toBe('endPhase')
  })

  it('build the search the Map sends, which the readers take back', () => {
    const search = transitionSearch(
      { part: 'play', index: 1 },
      'band:0',
      sceneEffect('nextScene', 'x'),
    )

    expect(search).toEqual({ step: 'play.1', carrier: 'band:0', effect: 'nextScene:x' })
    expect(pendingOf(search)).toEqual({
      step: { part: 'play', index: 1 },
      carrier: 'band:0',
      effect: { kind: 'nextScene', sceneType: 'x' },
    })
    expect(
      transitionSearch({ part: 'setup', index: 0 }, 'step', newEffect('endPhase')).effect,
    ).toBe('endPhase')
  })

  it('ask for no transition with no carrier and no effect, and refuse half of one', () => {
    expect(pendingOf({})).toBeNull()
    expect(pendingOf({ step: 'play.1' })).toBeNull()
    for (const half of [
      { carrier: 'step', effect: 'endPhase' },
      { step: 'play.0', carrier: 'step' },
      { step: 'play.0', effect: 'endPhase' },
      { effect: 'endPhase' },
      { carrier: 'step' },
    ]) {
      const result = pendingOf(half)
      expect(result !== null && 'refused' in result ? result.refused : '').toContain('incomplete')
    }
  })
})
