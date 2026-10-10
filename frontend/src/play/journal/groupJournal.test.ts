import { lostMineCampaign } from '../campaigns/fixtures'
import { gateNote, keepThreeRoll, lostMineJournal, stormyWeather } from './fixtures'
import { groupJournal } from './groupJournal'

describe('groupJournal', () => {
  it('groups entries by session and scene, keeping their recorded order', () => {
    const groups = groupJournal(lostMineCampaign.sessions, lostMineJournal)

    expect(
      groups.map(({ number, scenes }) => ({
        number,
        scenes: scenes.map((scene) => ({
          number: scene.number,
          title: scene.title,
          entries: scene.entries.map(({ id }) => id),
        })),
      })),
    ).toEqual([
      {
        number: 1,
        scenes: [{ number: 1, title: 'At the gate', entries: [gateNote.id, keepThreeRoll.id] }],
      },
      {
        number: 2,
        scenes: [
          {
            number: 1,
            title: 'Into the dark',
            entries: lostMineJournal.slice(2).map(({ id }) => id),
          },
        ],
      },
    ])
  })

  it('keeps sessions and scenes without entries, in number order', () => {
    const groups = groupJournal(
      [
        { number: 2, startedAt: '2026-10-06T12:00:00+00:00', scenes: [] },
        {
          number: 1,
          startedAt: '2026-10-05T12:00:00+00:00',
          scenes: [
            {
              number: 2,
              title: 'Later',
              startedAt: '2026-10-05T13:00:00+00:00',
              kind: 'scene',
              sceneType: null,
              sceneTypeName: null,
              hook: null,
            },
            {
              number: 1,
              title: 'First',
              startedAt: '2026-10-05T12:00:00+00:00',
              kind: 'scene',
              sceneType: null,
              sceneTypeName: null,
              hook: null,
            },
          ],
        },
      ],
      [],
    )

    expect(groups.map(({ number }) => number)).toEqual([1, 2])
    expect(groups[0]?.scenes.map(({ title }) => title)).toEqual(['First', 'Later'])
    expect(groups[1]?.scenes).toEqual([])
  })

  it('still shows an entry whose scene the campaign does not list, without a title', () => {
    const groups = groupJournal([], [stormyWeather])

    expect(groups).toEqual([
      { number: 2, scenes: [{ number: 1, title: null, entries: [stormyWeather] }] },
    ])
  })

  it('is empty before the first session', () => {
    expect(groupJournal([], [])).toEqual([])
  })
})
