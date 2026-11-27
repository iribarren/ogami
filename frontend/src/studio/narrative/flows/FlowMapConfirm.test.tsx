import { act, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { chooseOption } from '@/test/chooseOption'

import { narrativeFlows } from '../../fixtures'
import { flowToDraft, type FlowDraft } from './flowDraft'
import { noAnswer } from './flowError'
import { FlowMap } from './FlowMap'

// The Map reads the oracle tables from the API; none is needed here.
vi.mock('../../oracles/useGameSystemOracles', () => ({
  useGameSystemOracles: () => ({ isPending: false, isError: false, data: { tables: [] } }),
}))

const phase = (key: string, rest: Record<string, unknown> = {}) => ({
  key,
  name: key.toUpperCase(),
  mode: 'once',
  selection: { rule: 'sequence', sceneTypes: ['ambush'] },
  ...rest,
})

const draftOf = (phases: Record<string, unknown>[]): FlowDraft => {
  const draft = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases,
  })
  if (draft === null) {
    throw new Error('Expected a readable flow.')
  }
  return draft
}

const opening = { phaseOpening: [{ key: 'open', kind: 'prompt', title: 'Open' }] }
const first = draftOf([phase('one', opening), phase('two')])
// The same phases the other way round: the dialog opened on "one", which is now second.
const swapped = draftOf([phase('two'), phase('one', opening)])

function renderMap(draft: FlowDraft, locked: boolean) {
  const onEdit = vi.fn()
  const onOpen = vi.fn()
  const element = (next: FlowDraft, isLocked: boolean) => (
    <FlowMap
      gameSystemKey="my-draft"
      draft={next}
      narrative={narrativeFlows}
      answer={noAnswer}
      node={{ kind: 'settings' }}
      locked={isLocked}
      onOpen={onOpen}
      onEdit={onEdit}
    />
  )
  const view = render(element(draft, locked))
  return {
    onEdit,
    onOpen,
    change: (next: FlowDraft, isLocked: boolean) => {
      view.rerender(element(next, isLocked))
    },
  }
}

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  act(() => {
    screen.getByRole('button', { name: /^Hook: Phase opening/ }).focus()
  })
  await user.click(screen.getByRole('button', { name: 'Add transition' }))
  return within(await screen.findByRole('dialog', { name: 'Add a transition' }))
}

describe('confirming a transition on the Map', () => {
  it('refuses inside the dialog while a save is on its way, and edits nothing', async () => {
    const user = userEvent.setup()
    const { onEdit, change } = renderMap(first, false)
    const dialog = await openDialog(user)

    change(first, true)
    await user.click(dialog.getByRole('button', { name: 'Add transition' }))

    expect(dialog.getByRole('alert')).toHaveTextContent('A save is on its way')
    expect(onEdit).not.toHaveBeenCalled()
  })

  it('refuses inside the dialog when the phase it was opened on is no longer there', async () => {
    const user = userEvent.setup()
    const { onEdit, change } = renderMap(first, false)
    const dialog = await openDialog(user)
    // The end of the phase stays a destination whichever phase the index names.
    await chooseOption(user, 'To', 'End of the phase', dialog)

    change(swapped, false)
    await user.click(dialog.getByRole('button', { name: 'Add transition' }))

    expect(dialog.getByRole('alert')).toHaveTextContent('The phase changed; draw the arrow again.')
    expect(onEdit).not.toHaveBeenCalled()
  })

  it('confirms while the phase is still the one named', async () => {
    const user = userEvent.setup()
    const { onEdit } = renderMap(first, false)
    const dialog = await openDialog(user)

    await user.click(dialog.getByRole('button', { name: 'Add transition' }))

    expect(onEdit).toHaveBeenCalledTimes(1)
  })

  it('hands a Scene Type source to its page as the search it takes, and edits nothing here', async () => {
    const user = userEvent.setup()
    const { onEdit, onOpen } = renderMap(first, false)
    act(() => {
      screen.getAllByRole('button', { name: 'Scene Type: Ambush' })[0]?.focus()
    })
    await user.click(screen.getByRole('button', { name: 'Add transition' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Add a transition' }))
    await chooseOption(user, 'To', 'End of the phase', dialog)
    await chooseOption(user, 'Step', 'Play · 2 "React"', dialog)

    await user.click(dialog.getByRole('button', { name: 'Add transition' }))

    expect(onOpen).toHaveBeenCalledWith({
      type: 'sceneType',
      sceneType: 'ambush',
      pending: { step: 'play.1', carrier: 'step', effect: 'endPhase' },
    })
    expect(onEdit).not.toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('refuses a Scene Type source too while a save is on its way', async () => {
    const user = userEvent.setup()
    const { onOpen, change } = renderMap(first, false)
    act(() => {
      screen.getAllByRole('button', { name: 'Scene Type: Ambush' })[0]?.focus()
    })
    await user.click(screen.getByRole('button', { name: 'Add transition' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Add a transition' }))

    change(first, true)
    await user.click(dialog.getByRole('button', { name: 'Add transition' }))

    expect(dialog.getByRole('alert')).toHaveTextContent('A save is on its way')
    expect(onOpen).not.toHaveBeenCalled()
  })
})
