import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

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

describe('SheetFields with a 0..1 number field', () => {
  const condition: SheetFieldView = {
    ...field('angry', null),
    label: 'Angry',
    hint: 'Marked when provoked.',
    type: 'number',
    min: 0,
    max: 1,
    default: 0,
  }

  function renderCondition(draft: string, onChange = vi.fn(), source = condition) {
    render(
      <SheetFields
        template={{ fields: [source], layout: null }}
        drafts={{ angry: draft }}
        derived={{}}
        onChange={onChange}
      />,
    )
    return onChange
  }

  it('renders a toggle named after the field and described by its hint', () => {
    renderCondition('0')

    const toggle = screen.getByRole('checkbox', { name: 'Angry' })
    expect(toggle).not.toBeChecked()
    expect(toggle).toHaveAccessibleDescription('Marked when provoked.')
    expect(screen.queryByRole('spinbutton')).toBeNull()
  })

  it('reads 1 as checked and writes the same draft values', async () => {
    const user = userEvent.setup()
    const onChange = renderCondition('1')

    const toggle = screen.getByRole('checkbox', { name: 'Angry' })
    expect(toggle).toBeChecked()

    await user.click(toggle)
    expect(onChange).toHaveBeenCalledWith('angry', '0')
  })

  it('checks an unchecked toggle with 1', async () => {
    const user = userEvent.setup()
    const onChange = renderCondition('0')

    await user.click(screen.getByRole('checkbox', { name: 'Angry' }))
    expect(onChange).toHaveBeenCalledWith('angry', '1')
  })

  it('keeps a number input for a value outside 0..1, as legacy data may hold', () => {
    renderCondition('2')

    expect(screen.getByRole('spinbutton', { name: 'Angry' })).toHaveValue(2)
    expect(screen.queryByRole('checkbox')).toBeNull()
  })

  it('keeps a number input for any other range', () => {
    renderCondition('1', vi.fn(), { ...condition, max: 3 })
    expect(screen.getByRole('spinbutton', { name: 'Angry' })).toHaveValue(1)
  })
})

describe('SheetFields with a conditions field', () => {
  const wounds: SheetFieldView = {
    ...field('wounds', null),
    label: 'Wounds',
    hint: 'Each costs a die.',
    type: 'conditions',
    options: [
      { key: 'hurt', label: 'Hurt' },
      { key: 'shaken', label: 'Shaken' },
      { key: 'tired', label: 'Tired' },
    ],
  }

  function renderWounds(draft: string[], onChange = vi.fn()) {
    render(
      <SheetFields
        template={{ fields: [wounds], layout: null }}
        drafts={{ wounds: draft }}
        derived={{}}
        onChange={onChange}
      />,
    )
    return onChange
  }

  it('renders one checkbox per option, in declared order, checked when marked', () => {
    renderWounds(['tired', 'hurt'])

    const group = screen.getByRole('group', { name: 'Wounds' })
    expect(
      within(group)
        .getAllByRole('checkbox')
        .map((box) => box.getAttribute('aria-checked')),
    ).toEqual(['true', 'false', 'true'])
    expect(within(group).getAllByRole('checkbox')).toHaveLength(3)
    expect(screen.getByRole('checkbox', { name: 'Hurt' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Shaken' })).not.toBeChecked()
    expect(screen.getByText('Each costs a die.')).toBeInTheDocument()
  })

  it('marks an option by adding its key to the draft', async () => {
    const onChange = renderWounds(['hurt'])

    await userEvent.setup().click(screen.getByRole('checkbox', { name: 'Tired' }))

    expect(onChange).toHaveBeenCalledWith('wounds', ['hurt', 'tired'])
  })

  it('clears an option by taking its key out of the draft', async () => {
    const onChange = renderWounds(['hurt', 'tired'])

    await userEvent.setup().click(screen.getByRole('checkbox', { name: 'Hurt' }))

    expect(onChange).toHaveBeenCalledWith('wounds', ['tired'])
  })

  it('has nothing to tick for a field without options', () => {
    render(
      <SheetFields
        template={{ fields: [{ ...wounds, options: null }], layout: null }}
        drafts={{}}
        derived={{}}
        onChange={vi.fn()}
      />,
    )

    expect(screen.queryAllByRole('checkbox')).toEqual([])
  })
})
