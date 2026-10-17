import { render, screen } from '@testing-library/react'

import { SheetFields } from '@/shared/sheet/SheetFields'

import { nightJobCampaign, sheet, sheetLayout } from './fixtures'
import { sheetTemplate } from './sheetTemplate'

describe('the sheet layout fixture', () => {
  it('renders the label of every field of the sheet fixture', () => {
    const template = sheetTemplate({ ...nightJobCampaign.pinnedRelease, sheet, sheetLayout })

    render(<SheetFields template={template} drafts={{}} derived={{}} onChange={vi.fn()} />)

    // Each field by its own control, so a group legend with the same name cannot satisfy it.
    for (const field of sheet) {
      switch (field.type) {
        case 'list':
          expect(
            screen.getByRole('button', { name: `Add ${field.itemLabel ?? 'Item'}` }),
          ).toBeVisible()
          break
        case 'track':
          expect(screen.getByLabelText(`${field.label} current`)).toBeVisible()
          break
        default:
          expect(screen.getByLabelText(field.label)).toBeVisible()
      }
    }
  })

  it('declares the group of every field', () => {
    const declared = sheetLayout.groups.map((group) => group.name)

    for (const field of sheet) {
      expect(declared).toContain(field.group)
    }
  })
})
