import { render, screen } from '@testing-library/react'

import { SheetFields } from '@/shared/sheet/SheetFields'

import { nightJobCampaign, sheet, sheetLayout } from './fixtures'
import { sheetTemplate } from './sheetTemplate'

describe('the sheet layout fixture', () => {
  it('renders the label of every field of the sheet fixture', () => {
    const template = sheetTemplate({ ...nightJobCampaign.pinnedRelease, sheet, sheetLayout })

    render(<SheetFields template={template} drafts={{}} derived={{}} onChange={vi.fn()} />)

    for (const field of sheet) {
      expect(screen.getAllByText(field.label).length).toBeGreaterThan(0)
    }
  })

  it('declares the group of every field', () => {
    const declared = sheetLayout.groups.map((group) => group.name)

    for (const field of sheet) {
      expect(declared).toContain(field.group)
    }
  })
})
