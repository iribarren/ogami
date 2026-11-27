import { render } from '@testing-library/react'

import { buildCampaignLog } from './campaignLog'
import { buildChecksReference } from './checksReference'
import { CampaignLogSheet, ChecksReferenceSheet, TrackersSheet } from './ReferenceSheets'
import { readRelease } from './release'
import { miniRelease } from './testRelease'
import { buildTrackerSheet, WRITE_IN_ABOVE } from './trackerSheet'

const release = (change: (raw: ReturnType<typeof miniRelease>) => void = () => undefined) => {
  const raw = miniRelease()
  change(raw)
  return readRelease(raw)
}

const withNerve = (raw: ReturnType<typeof miniRelease>) => raw.flows[0]?.trackers.push('nerve')

describe('the checks reference sheet', () => {
  it('prints the checks, then the likelihood oracles with their answers and exceptional percent', () => {
    const { container } = render(
      <ChecksReferenceSheet reference={buildChecksReference(release(), 'mini')} paper="a5" />,
    )

    const sheet = container.querySelector('[data-sheet="checks"]')
    expect(sheet).toHaveClass('print-doc', 'print-a5')
    expect(sheet?.querySelector('[data-check="risk"]')?.textContent).toBe(
      'Risk roll (1d6)Roll a d6: high is good.Results: Goes wrong, Done.',
    )
    const oracle = sheet?.querySelector('[data-oracle="yes-no"]')
    expect(oracle?.querySelector('caption')?.textContent).toBe('Yes/no question: d100')
    expect([...(oracle?.querySelectorAll('tbody tr') ?? [])].map((row) => row.textContent)).toEqual(
      ['UnlikelyYes on 30 or less', 'LikelyYes on 70 or less'],
    )
    expect(oracle?.querySelector('tfoot')?.textContent).toMatch(/exceptional answer.*10%/)
  })

  it('prints a check without dice with no parentheses, and no oracle heading without oracles', () => {
    const reference = buildChecksReference(
      release((raw) => {
        Object.assign(raw.checks[0] ?? {}, { dice: undefined })
        raw.flows[0]?.oracles.splice(0, 1)
      }),
      'mini',
    )
    const { container } = render(<ChecksReferenceSheet reference={reference} paper="a4" />)

    expect(container.querySelector('h2')?.textContent).toBe('Risk roll')
    expect(container.querySelector('[data-oracle]')).toBeNull()
    expect([...container.querySelectorAll('h1')].map((h) => h.textContent)).toEqual(['Checks'])
  })

  it('prints nothing when there are no checks and no likelihood oracles', () => {
    const reference = buildChecksReference(
      release((raw) => {
        raw.checks.length = 0
        raw.flows[0]?.oracles.splice(0, 1)
      }),
      'mini',
    )
    const { container } = render(<ChecksReferenceSheet reference={reference} paper="a4" />)

    expect(container).toBeEmptyDOMElement()
  })
})

describe('the trackers and clocks sheet', () => {
  it('draws a clock as one shape per segment, black on white with no fill', () => {
    const { container } = render(
      <TrackersSheet trackers={buildTrackerSheet(release(), 'mini')} paper="a4" />,
    )

    const tracker = container.querySelector('[data-tracker="doom"]')
    expect(tracker?.querySelector('h2')?.textContent).toBe('Doom')
    expect(tracker?.textContent).toMatch(/Near\./)
    const shapes = [...(tracker?.querySelectorAll('svg path') ?? [])]
    expect(shapes).toHaveLength(6)
    for (const shape of shapes) {
      expect(shape).toHaveAttribute('fill', 'none')
      expect(shape).toHaveAttribute('stroke', '#000')
    }
  })

  it('draws a one-segment clock as a circle', () => {
    const trackers = buildTrackerSheet(
      release((raw) => Object.assign(raw.trackers[0] ?? {}, { segments: 1 })),
      'mini',
    )
    const { container } = render(<TrackersSheet trackers={trackers} paper="a4" />)

    expect(container.querySelectorAll('svg circle')).toHaveLength(1)
  })

  it('draws a counter as boxes with its level labels, and names its starting value', () => {
    const trackers = buildTrackerSheet(release(withNerve), 'mini')
    const { container } = render(<TrackersSheet trackers={trackers} paper="a4" />)

    const counter = container.querySelector('[data-tracker="nerve"]')
    expect(
      [...(counter?.querySelectorAll('.print-boxes li') ?? [])].map((li) => li.textContent),
    ).toEqual(['0Shaken', '1', '2Steady', '3', '4', '5'])
    expect(counter?.querySelector('.print-start')?.textContent).toBe('2Steady')
    expect(counter?.textContent).toMatch(/Starts at 2\./)
  })

  it('gives a counter over the box limit a write-in box with its range and its levels', () => {
    const trackers = buildTrackerSheet(
      release((raw) => {
        Object.assign(raw.trackers[1] ?? {}, { max: WRITE_IN_ABOVE + 5 })
        withNerve(raw)
      }),
      'mini',
    )
    const { container } = render(<TrackersSheet trackers={trackers} paper="a4" />)

    const counter = container.querySelector('[data-tracker="nerve"]')
    expect(counter?.querySelectorAll('.print-boxes')).toHaveLength(0)
    expect(counter?.querySelector('.print-write-in')?.textContent).toMatch(/\(0-25\)/)
    expect(counter?.querySelector('.print-levels')?.textContent).toBe('Shaken 0-1, Steady 2-25.')
  })

  it('prints no sheet for a flow without trackers', () => {
    const { container } = render(<TrackersSheet trackers={[]} paper="a4" />)

    expect(container).toBeEmptyDOMElement()
  })
})

describe('the campaign log sheet', () => {
  it('prints the Fact slots with a line, the Tags with blank lines, then the ruled lines', () => {
    const { container } = render(<CampaignLogSheet log={buildCampaignLog(release())} paper="a5" />)

    const sheet = container.querySelector('[data-sheet="log"]')
    expect(sheet).toHaveClass('print-doc', 'print-a5')
    expect([...(sheet?.querySelectorAll('h1, h2') ?? [])].map((h) => h.textContent)).toEqual([
      'Campaign log',
      'Fact slots',
      'Tags',
      'Log',
    ])
    const slot = sheet?.querySelector('[data-slot="sin"]')
    expect(slot?.textContent).toBe('The Sin What was done.')
    expect(slot?.querySelectorAll('.print-line')).toHaveLength(1)
    const tag = sheet?.querySelector('[data-tag="ally"]')
    expect(tag?.textContent).toBe('Ally Someone on your side.')
    expect(tag?.querySelectorAll('.print-line')).toHaveLength(4)
    expect(sheet?.querySelector('.print-ruled')).not.toBeNull()
  })

  it('still prints the ruled lines for a release without Fact slots and Tags', () => {
    const log = buildCampaignLog(
      release((raw) => Object.assign(raw, { factSlots: undefined, tags: undefined })),
    )
    const { container } = render(<CampaignLogSheet log={log} paper="a4" />)

    expect([...container.querySelectorAll('h1, h2')].map((h) => h.textContent)).toEqual([
      'Campaign log',
      'Log',
    ])
    expect(container.querySelector('.print-ruled')).not.toBeNull()
  })

  it('prints a Fact slot and a Tag that have no hint with just their label', () => {
    const log = buildCampaignLog(
      release((raw) => {
        Object.assign(raw.factSlots[0] ?? {}, { hint: undefined })
        Object.assign(raw.tags[0] ?? {}, { hint: undefined })
      }),
    )
    const { container } = render(<CampaignLogSheet log={log} paper="a4" />)

    expect(container.querySelector('[data-slot="sin"]')?.textContent).toBe('The Sin')
    expect(container.querySelector('[data-tag="ally"]')?.textContent).toBe('Ally')
  })
})
