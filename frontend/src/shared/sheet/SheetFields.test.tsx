import { render, screen } from '@testing-library/react'

import { SheetFields } from './SheetFields'
import type { SheetFieldView, SheetLayoutView, SheetTemplateView } from './types'

const field = (key: string, group: string | null, span = 1): SheetFieldView => ({
  key,
  label: key.toUpperCase(),
  hint: null,
  group,
  type: 'text',
  min: null,
  max: null,
  default: null,
  multiline: null,
  maxLength: null,
  itemLabel: null,
  maxItems: null,
  formula: null,
  span,
})

function renderSheet(template: SheetTemplateView) {
  return render(<SheetFields template={template} drafts={{}} derived={{}} onChange={vi.fn()} />)
}

const layout: SheetLayoutView = {
  columns: 2,
  groups: [
    { name: 'Notes', columns: 1, span: 2 },
    { name: 'Empty', columns: 1, span: 1 },
    { name: 'Stats', columns: 3, span: 1 },
  ],
}

describe('SheetFields with a layout', () => {
  const fields = [field('str', 'Stats', 2), field('dex', 'Stats'), field('bio', 'Notes')]

  it('renders the groups in declared order, not the order of the fields', () => {
    renderSheet({ fields, layout })

    const names = screen
      .getAllByRole('group')
      .map((group) => group.querySelector('legend')?.textContent)
    expect(names).toEqual(['Notes', 'Stats'])
  })

  it('renders the fields of a group together, in list order', () => {
    renderSheet({ fields, layout })

    const stats = screen.getByRole('group', { name: 'Stats' })
    expect(Array.from(stats.querySelectorAll('label')).map((label) => label.textContent)).toEqual([
      'STR',
      'DEX',
    ])
  })

  it('omits a group without fields', () => {
    renderSheet({ fields, layout })

    expect(screen.queryByRole('group', { name: 'Empty' })).not.toBeInTheDocument()
  })

  it('spans the groups across the sheet columns and the fields across the group columns', () => {
    const { container } = renderSheet({ fields, layout })

    expect(container.firstElementChild).toHaveClass('grid-cols-1', 'sm:grid-cols-2')
    expect(screen.getByRole('group', { name: 'Notes' })).toHaveClass('sm:col-span-2')
    expect(screen.getByRole('group', { name: 'Stats' })).not.toHaveClass('sm:col-span-2')
    const stats = screen.getByRole('group', { name: 'Stats' })
    expect(stats.querySelector('.grid')).toHaveClass('grid-cols-1', 'sm:grid-cols-3')
    expect(screen.getByLabelText('STR').closest('div.min-w-0')).toHaveClass('sm:col-span-2')
    expect(screen.getByLabelText('DEX').closest('div.min-w-0')).not.toHaveClass('sm:col-span-2')
  })
})

describe('SheetFields with a field whose group is not declared', () => {
  it('drops the field and the ungrouped ones: only declared groups render', () => {
    renderSheet({
      fields: [field('str', 'Stats'), field('lost', 'Missing'), field('free', null)],
      layout,
    })

    expect(screen.getByLabelText('STR')).toBeInTheDocument()
    expect(screen.queryByLabelText('LOST')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('FREE')).not.toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Missing' })).not.toBeInTheDocument()
  })
})

describe('SheetFields without a layout', () => {
  it('keeps consecutive fields of the same group together under its name', () => {
    renderSheet({
      fields: [field('a', 'One'), field('b', 'One'), field('c', null), field('d', 'One')],
      layout: null,
    })

    const groups = screen.getAllByRole('group')
    expect(groups.map((group) => group.querySelector('legend')?.textContent)).toEqual([
      'One',
      'One',
    ])
    expect(groups[0]?.querySelectorAll('label')).toHaveLength(2)
    expect(groups[1]?.querySelectorAll('label')).toHaveLength(1)
    expect(screen.getByLabelText('C')).toBeInTheDocument()
    expect(document.querySelector('[class*="grid"]')).toBeNull()
  })
})
