import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'

import { renderWithApi } from '@/test/renderWithApi'

import { lostMineCampaign } from '../campaigns/fixtures'
import {
  fateDouble,
  fateDoubleEvent,
  gateNote,
  keepThreeRoll,
  lockedDoorAnswer,
  recordedEntry,
  skillCheckEntry,
  stormyWeather,
} from './fixtures'
import { NoteComposer } from './NoteComposer'
import type { JournalEntry } from './useJournal'

const campaignId = lostMineCampaign.id
const journalPath = `GET /api/campaigns/${campaignId}/journal`
const notePath = `POST /api/campaigns/${campaignId}/journal/notes`

/** Session 2, scene 1: the stormy weather, a fate answer and a fate question with its event. */
const journal: JournalEntry[] = [
  gateNote,
  keepThreeRoll,
  stormyWeather,
  lockedDoorAnswer,
  fateDouble,
  fateDoubleEvent,
  skillCheckEntry,
]

function renderComposer(
  current: { session: number; scene: number } | null,
  sent: unknown[] = [],
  noteResponse: () => Response = () =>
    Response.json(recordedEntry({ kind: 'note', text: 'Done.', attachments: [] }), { status: 201 }),
) {
  renderWithApi(
    <NoteComposer campaignId={campaignId} disabled={current === null} current={current} />,
    {
      [journalPath]: () => Response.json(journal),
      [notePath]: async (request) => {
        sent.push(await request.json())
        return noteResponse()
      },
    },
  )
}

async function openPicker() {
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: /^Attach results/ }))
  return user
}

describe('NoteComposer attachment limit', () => {
  it('disables the boxes left once 20 results are picked, with a hint', async () => {
    const rolls = Array.from({ length: 21 }, (_, index) =>
      recordedEntry(
        { kind: 'roll', expression: `1d${String(index + 2)}`, total: 1, groups: [] },
        `0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80${String(index).padStart(2, '0')}`,
      ),
    )
    renderWithApi(
      <NoteComposer campaignId={campaignId} disabled={false} current={{ session: 2, scene: 1 }} />,
      {
        [journalPath]: () => Response.json(rolls),
      },
    )
    const user = await openPicker()
    const boxes = screen.getAllByRole('checkbox')
    expect(boxes).toHaveLength(21)
    expect(screen.queryByText('Up to 20 results can be attached.')).not.toBeInTheDocument()

    for (const box of boxes.slice(0, 20)) {
      await user.click(box)
    }

    expect(screen.getByText('Up to 20 results can be attached.')).toBeInTheDocument()
    const left = screen.getAllByRole('checkbox').filter((box) => !(box as HTMLInputElement).checked)
    expect(left).toHaveLength(1)
    expect(left[0]).toBeDisabled()
    // The picked ones stay usable, so one can be dropped to make room.
    const [picked] = screen.getAllByRole('checkbox')
    expect(picked).toBeEnabled()
    await user.click(picked ?? screen.getByRole('checkbox', { checked: true }))
    expect(left[0]).toBeEnabled()
  })
})

describe('NoteComposer attachments', () => {
  it('lists only the attachable results of the current scene, newest first', async () => {
    renderComposer({ session: 2, scene: 1 })

    await openPicker()

    const labels = screen.getAllByRole('checkbox').map((box) => box.closest('label')?.textContent)
    // The skill check is in session 2, scene 1 as well; the note, the random event and the
    // results of session 1 are not offered.
    expect(labels).toHaveLength(4)
    expect(labels[0]).toContain('Skill')
    expect(labels[1]).toBe('Does the guard notice? → Yes')
    expect(labels[2]).toBe('Is the door locked? → Exceptional yes')
    expect(labels[3]).toBe('Weather: Hail')
  })

  it('offers nothing before a scene starts', () => {
    renderComposer(null)

    expect(screen.queryByRole('button', { name: /^Attach results/ })).not.toBeInTheDocument()
  })

  it('sends the picked ids in journal order, then clears the picks', async () => {
    const sent: unknown[] = []
    renderComposer({ session: 2, scene: 1 }, sent)
    const user = await openPicker()

    await user.click(screen.getByRole('checkbox', { name: /^Does the guard notice/ }))
    await user.click(screen.getByRole('checkbox', { name: /^Weather/ }))
    expect(screen.getByRole('button', { name: 'Attach results (2)' })).toBeInTheDocument()
    await user.type(screen.getByLabelText('New note'), 'Done.')
    await user.click(screen.getByRole('button', { name: 'Write note' }))

    await waitFor(() => {
      expect(screen.getByLabelText('New note')).toHaveValue('')
    })
    expect(sent).toEqual([{ text: 'Done.', attachments: [stormyWeather.id, fateDouble.id] }])
    expect(screen.getByRole('button', { name: 'Attach results' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })

  it('sends no attachments when nothing is picked', async () => {
    const sent: unknown[] = []
    renderComposer({ session: 2, scene: 1 }, sent)
    await openPicker()

    await userEvent.type(screen.getByLabelText('New note'), 'Done.')
    await userEvent.click(screen.getByRole('button', { name: 'Write note' }))

    await waitFor(() => {
      expect(sent).toEqual([{ text: 'Done.' }])
    })
  })

  it('keeps the picks and shows the refusal when the note is refused', async () => {
    renderComposer({ session: 2, scene: 1 }, [], () =>
      Response.json({ error: 'Journal entry cannot be attached.' }, { status: 422 }),
    )
    const user = await openPicker()

    await user.click(screen.getByRole('checkbox', { name: /^Weather/ }))
    await user.type(screen.getByLabelText('New note'), 'Done.')
    await user.click(screen.getByRole('button', { name: 'Write note' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Journal entry cannot be attached.')
    expect(screen.getByRole('checkbox', { name: /^Weather/ })).toBeChecked()
    expect(screen.getByLabelText('New note')).toHaveValue('Done.')
  })

  it('stops offering the results of a scene once play moves on', async () => {
    renderWithApi(<SceneChanger />, {
      [journalPath]: () => Response.json(journal),
    })
    const user = await openPicker()
    await user.click(screen.getByRole('checkbox', { name: /^Weather/ }))

    await user.click(screen.getByRole('button', { name: 'Next scene' }))

    expect(screen.queryByRole('button', { name: /^Attach results/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })
})

function SceneChanger() {
  const [scene, setScene] = useState(1)
  return (
    <>
      <button
        type="button"
        onClick={() => {
          setScene(scene + 1)
        }}
      >
        Next scene
      </button>
      <NoteComposer campaignId={campaignId} disabled={false} current={{ session: 2, scene }} />
    </>
  )
}
