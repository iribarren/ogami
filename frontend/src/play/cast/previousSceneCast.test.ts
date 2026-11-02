import { previousSceneCast } from './previousSceneCast'
import { castCampaign } from './fixtures'
import type { Campaign } from '../campaigns/useCampaigns'

const [pastSession, currentSession] = castCampaign.sessions as [
  Campaign['sessions'][number],
  Campaign['sessions'][number],
]
const scene = pastSession.scenes[0]
if (scene === undefined) {
  throw new Error('The fixture has no scene.')
}
const mira = [{ id: 'mira', name: 'Mira' }]
const bo = [{ id: 'bo', name: 'Bo' }]
const inSession = (number: number, ...scenes: (typeof scene)[]) => ({
  ...pastSession,
  number,
  scenes,
})

describe('previousSceneCast', () => {
  it('is the cast of the scene before it in the same session', () => {
    const sessions = [
      inSession(1, { ...scene, number: 1, cast: mira }, { ...scene, number: 2, cast: bo }),
    ]

    expect(previousSceneCast(sessions, 1, 2)).toEqual(mira)
  })

  it('is the last scene of an earlier session for the first scene of a session', () => {
    const sessions = [
      inSession(1, { ...scene, number: 1, cast: bo }, { ...scene, number: 2, cast: mira }),
      inSession(2, { ...scene, number: 1, cast: [] }),
    ]

    expect(previousSceneCast(sessions, 2, 1)).toEqual(mira)
  })

  it('skips hook scenes, which have no cast of their own', () => {
    const sessions = [
      inSession(
        1,
        { ...scene, number: 1, cast: mira },
        { ...scene, number: 2, cast: [], hook: 'worldTurn', kind: 'hook' },
        { ...scene, number: 3, cast: [] },
      ),
    ]

    expect(previousSceneCast(sessions, 1, 3)).toEqual(mira)
  })

  it('is undefined for the very first scene and ignores later sessions', () => {
    expect(previousSceneCast([currentSession], currentSession.number, 1)).toBeUndefined()
    expect(previousSceneCast([pastSession, currentSession], 1, 1)).toBeUndefined()
  })
})
