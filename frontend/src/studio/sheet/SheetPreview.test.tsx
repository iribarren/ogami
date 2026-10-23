import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { sheet } from '../fixtures'
import { readLayout, type LayoutDraft } from './layoutDraft'
import { SheetPreview as Preview } from './SheetPreview'
import type { GameSystemSheet } from './useGameSystemSheet'
import { useSampleValues, useSheetSamples } from './useSheetSamples'

const stored = sheet as GameSystemSheet

/** The preview with the sample values the Sheet tab keeps for it. */
function SheetPreview({
  sheet: shown,
  layoutDraft,
}: {
  sheet: GameSystemSheet
  layoutDraft: LayoutDraft | null
}) {
  return <Preview sheet={shown} samples={useSheetSamples(useSampleValues(), shown, layoutDraft)} />
}

const preview = () => within(screen.getByRole('region', { name: 'Preview' }))

function layoutOf(source: GameSystemSheet): LayoutDraft {
  const draft = readLayout(source)
  if (draft === null) {
    throw new Error('The fixture layout should be readable.')
  }
  return draft
}

describe('the sheet preview', () => {
  it('renders the groups and their fields per the layout, empty groups left out', () => {
    render(<SheetPreview sheet={stored} layoutDraft={null} />)

    const groups = preview()
      .getAllByRole('group')
      .map((group) => group.querySelector('legend')?.textContent)
    expect(groups).toContain('Stats')
    expect(groups).toContain('Health')
    expect(groups).not.toContain('Notes')
    expect(preview().getByLabelText('Power')).toBeInTheDocument()
    expect(preview().getByLabelText('Dexterity')).toHaveValue(10)
  })

  it('says so when the sheet has no fields', () => {
    render(<SheetPreview sheet={{ ...stored, fields: [] }} layoutDraft={null} />)

    expect(preview().getByText('Add a field to preview the sheet.')).toBeVisible()
  })

  it('computes a derived field from the sample values as they are typed', async () => {
    const user = userEvent.setup()
    render(<SheetPreview sheet={stored} layoutDraft={null} />)
    expect(preview().getByLabelText('Bonus')).toHaveTextContent('1')

    await user.clear(preview().getByLabelText('Power'))
    await user.type(preview().getByLabelText('Power'), '7')

    expect(preview().getByLabelText('Bonus')).toHaveTextContent('8')
  })

  it('starts an untyped number field where the check sandbox does, within its range', async () => {
    const user = userEvent.setup()
    const ranged = {
      ...stored,
      fields: stored.fields.map((field) => (field.key === 'pow' ? { ...field, min: 4 } : field)),
    }
    render(<SheetPreview sheet={ranged} layoutDraft={null} />)

    // No default, a minimum of 4: the sandbox starts it at 4, so the derived field reads 4 + 1.
    expect(preview().getByLabelText('Power')).toHaveValue(4)
    expect(preview().getByLabelText('Bonus')).toHaveTextContent('5')
    expect(preview().getByLabelText('Dexterity')).toHaveValue(10)

    // What is typed still wins.
    await user.clear(preview().getByLabelText('Power'))
    await user.type(preview().getByLabelText('Power'), '9')
    expect(preview().getByLabelText('Bonus')).toHaveTextContent('10')
  })

  it('feeds a derived field the sample Tracker value', async () => {
    const user = userEvent.setup()
    render(
      <SheetPreview
        sheet={{
          ...stored,
          fields: [
            {
              key: 'risk',
              label: 'Risk',
              group: 'Stats',
              type: 'derived',
              formula: '{tracker:heat} * 2',
            },
          ],
        }}
        layoutDraft={null}
      />,
    )
    expect(preview().getByLabelText('Risk')).toHaveTextContent('0')

    await user.clear(preview().getByLabelText('Heat'))
    await user.type(preview().getByLabelText('Heat'), '4')
    expect(preview().getByLabelText('Risk')).toHaveTextContent('8')

    await user.clear(preview().getByLabelText('Heat'))
    expect(preview().getByLabelText('Risk')).toHaveTextContent('incomplete value')
  })

  it('shows the unsaved layout draft instead of the saved layout', () => {
    const draft = layoutOf(stored)
    const edited = {
      columns: 1,
      groups: draft.groups.map((group, at) =>
        at === 0 ? { ...group, name: 'Attributes' } : group,
      ),
    }
    const { rerender } = render(<SheetPreview sheet={stored} layoutDraft={null} />)

    rerender(<SheetPreview sheet={stored} layoutDraft={edited} />)

    expect(preview().getByText('Attributes', { selector: 'legend' })).toBeVisible()
    expect(preview().queryByText('Stats', { selector: 'legend' })).not.toBeInTheDocument()
    // The field follows its renamed group instead of vanishing.
    expect(preview().getByLabelText('Power')).toBeInTheDocument()
  })

  it('falls back to the saved layout while the draft is invalid', () => {
    const draft = layoutOf(stored)
    const invalid = {
      ...draft,
      groups: draft.groups.map((group, at) => (at === 0 ? { ...group, name: '' } : group)),
    }

    render(<SheetPreview sheet={stored} layoutDraft={invalid} />)

    expect(preview().getByText('Stats', { selector: 'legend' })).toBeVisible()
  })

  it('does not crash on odd read-model data: no layout, an undeclared group, a cycle', () => {
    render(
      <SheetPreview
        sheet={{
          ...stored,
          groups: null,
          fields: [
            { key: 'a', label: 'A', group: 'Ghost', type: 'derived', formula: '{b}' },
            { key: 'b', label: 'B', type: 'derived', formula: '{a}' },
            { key: 'c', label: 'C', type: 'derived', formula: '1 +' },
          ],
        }}
        layoutDraft={null}
      />,
    )

    expect(preview().getByLabelText('A')).toHaveTextContent('invalid formula')
    expect(preview().getByLabelText('C')).toHaveTextContent('invalid formula')
  })

  it('starts a sample again when its field changes type or disappears', async () => {
    const user = userEvent.setup()
    const { rerender } = render(<SheetPreview sheet={stored} layoutDraft={null} />)
    await user.clear(preview().getByLabelText('Power'))
    await user.type(preview().getByLabelText('Power'), '7')
    const field = (type: string, extra = {}) => ({
      key: 'pow',
      label: 'Power',
      group: 'Stats',
      type,
      ...extra,
    })

    rerender(<SheetPreview sheet={{ ...stored, fields: [field('text')] }} layoutDraft={null} />)
    expect(preview().getByLabelText('Power')).toHaveValue('')

    rerender(<SheetPreview sheet={{ ...stored, fields: [] }} layoutDraft={null} />)
    rerender(
      <SheetPreview
        sheet={{ ...stored, fields: [field('number', { default: 3 })] }}
        layoutDraft={null}
      />,
    )
    expect(preview().getByLabelText('Power')).toHaveValue(3)
  })
})
