import { fireEvent, render, screen, within } from '@testing-library/react'
import { useState } from 'react'

import { stepPickers } from '../../fixtures'
import { StepListEditor } from './StepListEditor'
import { stepsToDrafts } from './stepDraft'

/** One tile is drawn per card, so the tiles drawn since a point in time count the cards that rendered. */
const drawn = vi.hoisted(() => ({ tiles: 0 }))

vi.mock('./stepKindTile', async (importOriginal) => {
  const { StepKindTile: Tile } = await importOriginal<typeof import('./stepKindTile')>()
  return {
    StepKindTile: (props: { kind: string }) => {
      drawn.tiles += 1
      return <Tile {...props} />
    },
  }
})

const stored = Array.from({ length: 6 }, (_, at) => ({
  key: `step-${String(at + 1)}`,
  kind: 'prompt',
  title: `Step title ${String(at + 1)}`,
  prompt: 'Say something.',
}))

function Harness() {
  const [steps, setSteps] = useState(() => stepsToDrafts(stored))
  return (
    <>
      <StepListEditor steps={steps} context={stepPickers} onChange={setSteps} />
      <output aria-label="Titles">
        {steps.map((step) => ('title' in step ? step.title : '')).join('|')}
      </output>
    </>
  )
}

const title = (number: number) =>
  within(screen.getByRole('group', { name: `Step ${String(number)}` })).getByLabelText('Title')

describe('the step cards', () => {
  beforeEach(() => {
    drawn.tiles = 0
  })

  it('renders only the edited card when one step changes', () => {
    render(<Harness />)
    expect(drawn.tiles).toBe(stored.length)
    drawn.tiles = 0

    fireEvent.change(title(1), { target: { value: 'Changed' } })

    expect(title(1)).toHaveValue('Changed')
    expect(drawn.tiles).toBe(1)
  })

  it('keeps both of two quick edits on different cards', () => {
    render(<Harness />)

    fireEvent.change(title(2), { target: { value: 'Second' } })
    fireEvent.change(title(5), { target: { value: 'Fifth' } })
    fireEvent.change(title(2), { target: { value: 'Second again' } })

    expect(screen.getByLabelText('Titles')).toHaveTextContent(
      'Step title 1|Second again|Step title 3|Step title 4|Fifth|Step title 6',
    )
  })
})
