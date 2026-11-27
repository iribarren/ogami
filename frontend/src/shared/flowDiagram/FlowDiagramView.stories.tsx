import type { Meta, StoryObj } from '@storybook/react-vite'

import { FlowDiagramView } from './FlowDiagramView'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { phaseDiagram } from './phaseDiagram'

// The same preset files PHP publishes (backend/presets/), as the print entry reads them, so a
// story cannot drift from a real flow. The node container mounts them, see compose.yaml.
const presets = import.meta.glob<unknown>(
  '../../../../backend/presets/{dogwatch,hard-country,ironsworn}.json',
  { eager: true, import: 'default' },
)

function flow(file: string, flowKey: string) {
  return flowDiagramFromReleaseContent(presets[`../../../../backend/presets/${file}.json`], flowKey)
}

const meta = {
  title: 'Shared/FlowDiagram',
  component: FlowDiagramView,
  parameters: { layout: 'padded' },
} satisfies Meta<typeof FlowDiagramView>

export default meta
type Story = StoryObj<typeof meta>

export const ColdBerth: Story = {
  args: { diagram: flow('dogwatch', 'cold-berth') },
}

export const TheLongCarry: Story = {
  args: { diagram: flow('hard-country', 'the-long-carry') },
}

/** One phase, for a manual placement: the phase it ends in is an exit stub. */
export const OnePhaseCut: Story = {
  args: { diagram: phaseDiagram(flow('hard-country', 'the-long-carry'), 'the-road') },
}

/** A dense flow: 47 Scene Type arrows. */
export const Ironsworn: Story = {
  args: { diagram: flow('ironsworn', 'ironsworn') },
}
