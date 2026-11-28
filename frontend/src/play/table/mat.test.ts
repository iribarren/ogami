import {
  lostMineCampaign,
  lostMineInNewSession,
  newCampaign,
  freeJournal,
} from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { matOf } from './mat'

type Scene = Campaign['sessions'][number]['scenes'][number]

const scene = (number: number, title: string, extra: Partial<Scene> = {}): Scene => ({
  number,
  title,
  startedAt: '2026-10-06T12:05:00+00:00',
  kind: 'scene',
  sceneType: 'legwork',
  sceneTypeName: 'Legwork',
  hook: null,
  cast: [],
  characters: [],
  ...extra,
})

function campaignOf(
  sessions: { number: number; scenes: Scene[]; endedAt?: string | null }[],
  current: { session: number | null; scene: number | null },
): Campaign {
  return {
    ...lostMineCampaign,
    sessions: sessions.map(({ number, scenes, endedAt }) => ({
      number,
      scenes,
      startedAt: '2026-10-06T12:00:00+00:00',
      endedAt: endedAt === undefined ? null : endedAt,
      party: [],
    })),
    currentSessionNumber: current.session,
    currentSceneNumber: current.scene,
  }
}

const ended = '2026-10-06T14:00:00+00:00'

describe('matOf', () => {
  it('lays this session in play order, flags the current scene and the hook scenes', () => {
    const mat = matOf(
      campaignOf(
        [
          {
            number: 1,
            scenes: [
              scene(2, 'The vault'),
              scene(1, 'Opening', {
                kind: 'hook',
                hook: 'sessionOpening',
                sceneType: null,
                sceneTypeName: null,
              }),
              scene(3, 'The getaway'),
            ],
          },
        ],
        { session: 1, scene: 3 },
      ),
    )

    expect(mat.row.map(({ title, current, hook }) => [title, current, hook])).toEqual([
      ['Opening', false, true],
      ['The vault', false, false],
      ['The getaway', true, false],
    ])
    expect(mat.row[1]).toMatchObject({ sessionNumber: 1, number: 2, sceneType: 'Legwork' })
    expect(mat.piles).toEqual([])
  })

  it('stacks earlier sessions as piles, oldest first, the last scene on top', () => {
    const mat = matOf(
      campaignOf(
        [
          { number: 1, scenes: [scene(1, 'At the gate'), scene(2, 'The door')], endedAt: ended },
          { number: 2, scenes: [scene(1, 'The stairs')], endedAt: ended },
          { number: 3, scenes: [scene(1, 'Now')] },
        ],
        { session: 3, scene: 1 },
      ),
    )

    expect(mat.row.map(({ title }) => title)).toEqual(['Now'])
    expect(
      mat.piles.map(({ sessionNumber, sceneCount, top }) => [sessionNumber, sceneCount, top.title]),
    ).toEqual([
      [1, 2, 'The door'],
      [2, 1, 'The stairs'],
    ])
    expect(mat.piles[0]?.cards.map(({ title }) => title)).toEqual(['At the gate', 'The door'])
    expect(mat.piles.flatMap(({ cards }) => cards).some(({ current }) => current)).toBe(false)
  })

  it('has an empty mat for a campaign with no session yet', () => {
    expect(matOf(newCampaign('New', freeJournal))).toEqual({ row: [], piles: [] })
  })

  it('has an empty row, and no pile, for a session under way with no scene yet', () => {
    const mat = matOf(campaignOf([{ number: 1, scenes: [] }], { session: 1, scene: null }))

    expect(mat).toEqual({ row: [], piles: [] })
  })

  it('leaves out a session that has no scene instead of piling nothing', () => {
    const mat = matOf(
      campaignOf(
        [
          { number: 1, scenes: [], endedAt: ended },
          { number: 2, scenes: [scene(1, 'Once')], endedAt: ended },
        ],
        { session: null, scene: null },
      ),
    )

    expect(mat.piles.map(({ sessionNumber }) => sessionNumber)).toEqual([2])
  })

  it('piles every session when none is under way', () => {
    const mat = matOf({
      ...lostMineCampaign,
      sessions: lostMineCampaign.sessions.map((session) => ({ ...session, endedAt: ended })),
      currentSessionNumber: null,
      currentSceneNumber: null,
    })

    expect(mat.row).toEqual([])
    expect(mat.piles.map(({ sessionNumber, sceneCount }) => [sessionNumber, sceneCount])).toEqual([
      [1, 1],
      [2, 1],
    ])
  })

  it('treats a campaign that waits for its next session as between sessions', () => {
    const waiting = {
      ...lostMineCampaign,
      flowRun: { status: 'waiting', waitsForSession: true } as unknown as Campaign['flowRun'],
    }

    expect(matOf(waiting).row).toEqual([])
    expect(matOf(waiting).piles).toHaveLength(2)
  })

  it('keeps the scenes of a session right after it starts out of the previous piles', () => {
    const mat = matOf(lostMineInNewSession)

    expect(mat.row).toEqual([])
    expect(mat.piles.map(({ sessionNumber }) => sessionNumber)).toEqual([1, 2])
  })

  it('reads legacy scenes with no Scene Type or a missing name', () => {
    const legacy = scene(1, 'Old scene', { sceneType: null, sceneTypeName: null })
    const half = scene(2, 'Half old', { sceneTypeName: null })
    const mat = matOf(campaignOf([{ number: 1, scenes: [legacy, half] }], { session: 1, scene: 2 }))

    expect(mat.row.map(({ sceneType }) => sceneType)).toEqual([null, 'legwork'])
  })

  it('flags no scene when the current scene number is not in the session', () => {
    const mat = matOf(
      campaignOf([{ number: 1, scenes: [scene(1, 'Only')] }], { session: 1, scene: 7 }),
    )

    expect(mat.row.map(({ current }) => current)).toEqual([false])
  })
})
