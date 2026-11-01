import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { fulfillCheck, gripCheck, luckCheck, sneakCheck } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { nightJobCampaign, nightJobWithChecks, viCharacter } from '../characters/fixtures'
import {
  cancelledDieEntry,
  edgeRollEntry,
  matchEntry,
  recordedEntry,
  skillCheckEntry,
  vowProgressEntry,
} from '../journal/fixtures'

const path = `/api/campaigns/${nightJobCampaign.id}`

/** The Play screen of `campaign`; `sent` collects the bodies posted to the check route. */
function renderCharacters(campaign: Campaign, check?: FakeHandler) {
  const sent: unknown[] = []
  const result = renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
    ...(check === undefined
      ? {}
      : {
          [`POST ${path}/journal/checks`]: async (request: Request) => {
            sent.push(await request.clone().json())
            return check(request)
          },
        }),
  })
  return { ...result, sent }
}

const recorded = () => Response.json(recordedEntry(skillCheckEntry.content), { status: 201 })

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Roll a check for Vi' }))
  return within(await screen.findByRole('dialog', { name: 'Roll a check for Vi' }))
}

describe('the "Roll a check" dialog of the Characters panel', () => {
  it('has no action when the release defines no checks', async () => {
    renderCharacters(nightJobCampaign)

    expect(await screen.findByText('Vi')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Roll a check/ })).not.toBeInTheDocument()
  })

  it('opens a labelled dialog with the check, its hint and the difficulty at its default', async () => {
    const user = userEvent.setup()
    renderCharacters(nightJobWithChecks)

    const dialog = await openDialog(user)

    expect(screen.getByRole('dialog')).toHaveAccessibleDescription(/recorded in the journal/)
    expect(dialog.getByLabelText('Check')).toHaveValue('skill')
    expect(dialog.getByText('A stat and 1d10 against a Difficulty Value.')).toBeInTheDocument()
    const difficulty = dialog.getByLabelText('Difficulty Value')
    expect(difficulty).toHaveValue(13)
    expect(difficulty).toHaveAttribute('min', '9')
    expect(difficulty).toHaveAttribute('max', '29')
    expect(dialog.getByLabelText('Situational modifier')).toHaveValue(0)
  })

  it('rolls with the difficulty and the situational modifier, then closes', async () => {
    const user = userEvent.setup()
    const { sent } = renderCharacters(nightJobWithChecks, recorded)

    const dialog = await openDialog(user)
    await user.clear(dialog.getByLabelText('Difficulty Value'))
    await user.type(dialog.getByLabelText('Difficulty Value'), '17')
    await user.clear(dialog.getByLabelText('Situational modifier'))
    await user.type(dialog.getByLabelText('Situational modifier'), '-2')
    await user.click(dialog.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { check: 'skill', character: viCharacter.id, difficulty: 17, situational: -2 },
      ])
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('sends the default difficulty and no situational modifier when the player changes nothing', async () => {
    const user = userEvent.setup()
    const { sent } = renderCharacters(nightJobWithChecks, recorded)

    const dialog = await openDialog(user)
    await user.click(dialog.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ check: 'skill', character: viCharacter.id, difficulty: 13 }])
    })
  })

  it('asks no difficulty for a check that declares none, and resets it when the check changes', async () => {
    const user = userEvent.setup()
    const { sent } = renderCharacters(nightJobWithChecks, recorded)

    const dialog = await openDialog(user)
    await user.clear(dialog.getByLabelText('Difficulty Value'))
    await user.type(dialog.getByLabelText('Difficulty Value'), '20')
    await user.selectOptions(dialog.getByLabelText('Check'), 'Luck')

    expect(dialog.queryByLabelText('Difficulty Value')).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Roll' }))
    await waitFor(() => {
      expect(sent).toEqual([{ check: 'luck', character: viCharacter.id }])
    })
  })

  it('shows a single check as text, not a select', async () => {
    const user = userEvent.setup()
    renderCharacters({ ...nightJobWithChecks, checks: [luckCheck] })

    const dialog = await openDialog(user)

    expect(dialog.queryByLabelText('Check')).not.toBeInTheDocument()
    expect(dialog.getByText('Luck')).toBeInTheDocument()
  })

  it('keeps the dialog open and shows the refusal of the server', async () => {
    const user = userEvent.setup()
    renderCharacters(nightJobWithChecks, () =>
      Response.json(
        { error: 'Check "skill" cannot be performed: The formula divides by zero.' },
        { status: 422 },
      ),
    )

    const dialog = await openDialog(user)
    await user.click(dialog.getByRole('button', { name: 'Roll' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(
      'Check "skill" cannot be performed: The formula divides by zero.',
    )
    expect(dialog.getByRole('button', { name: 'Roll' })).toBeEnabled()
  })

  it('closes on Escape without rolling', async () => {
    const user = userEvent.setup()
    const { sent } = renderCharacters(nightJobWithChecks, recorded)

    await openDialog(user)
    await user.keyboard('{Escape}')

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(sent).toEqual([])
  })

  it('shows the dice of a pool check and labels the situational input as extra dice', async () => {
    const user = userEvent.setup()
    const { sent } = renderCharacters(
      { ...nightJobCampaign, checks: [sneakCheck, gripCheck] },
      recorded,
    )

    const dialog = await openDialog(user)

    expect(
      dialog.getByText('Pool: Agility + Stealth − Physical d6, success on 5+'),
    ).toBeInTheDocument()
    expect(dialog.queryByLabelText('Situational modifier')).not.toBeInTheDocument()
    expect(dialog.getByLabelText('Extra dice')).toHaveValue(0)
    await user.selectOptions(dialog.getByLabelText('Check'), 'grip')
    expect(dialog.getByText('Pool: 4d6, success on 6+')).toBeInTheDocument()
    await user.clear(dialog.getByLabelText('Extra dice'))
    await user.type(dialog.getByLabelText('Extra dice'), '-1')
    await user.click(dialog.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ check: 'grip', character: viCharacter.id, situational: -1 }])
    })
  })

  it('rolls a progress roll, which has no dice and no pool, from the form', async () => {
    const user = userEvent.setup()
    const { sent } = renderCharacters({ ...nightJobCampaign, checks: [fulfillCheck] }, () =>
      Response.json(vowProgressEntry, { status: 201 }),
    )

    const dialog = await openDialog(user)

    expect(dialog.getByText('Fulfill your vow')).toBeInTheDocument()
    expect(dialog.queryByText(/Pool:/)).not.toBeInTheDocument()
    expect(dialog.queryByLabelText('Extra dice')).not.toBeInTheDocument()
    expect(dialog.getByLabelText('Situational modifier')).toHaveValue(0)
    await user.clear(dialog.getByLabelText('Situational modifier'))
    await user.type(dialog.getByLabelText('Situational modifier'), '2')
    await user.click(dialog.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ check: 'fulfill', character: viCharacter.id, situational: 2 }])
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  describe('a challenge roll (ADR 0049)', () => {
    const rollWith = async (entry: typeof edgeRollEntry) => {
      const user = userEvent.setup()
      renderCharacters(nightJobWithChecks, () => Response.json(entry, { status: 201 }))
      const dialog = await openDialog(user)
      await user.click(dialog.getByRole('button', { name: 'Roll' }))
      return dialog
    }

    it('closes on a plain roll, as a dice roll does', async () => {
      await rollWith(edgeRollEntry)

      await waitFor(() => {
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      })
    })

    it('stays open on a match, for the player to read', async () => {
      const dialog = await rollWith(matchEntry)

      expect(await dialog.findByRole('list', { name: 'Challenge dice' })).toBeInTheDocument()
      expect(dialog.getByText('Match')).toBeInTheDocument()
      expect(dialog.getByRole('button', { name: 'Done' })).toBeInTheDocument()
      expect(dialog.queryByRole('button', { name: /Push/ })).not.toBeInTheDocument()
    })

    it('stays open on a cancelled action die', async () => {
      const dialog = await rollWith(cancelledDieEntry)

      expect(await dialog.findByText(/cancelled the action die/)).toBeInTheDocument()
      expect(dialog.getByRole('button', { name: 'Done' })).toBeInTheDocument()
    })
  })
})
