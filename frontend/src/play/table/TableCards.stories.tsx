import type { Meta, StoryObj } from '@storybook/react-vite'

import {
  DeckBack,
  DeckFront,
  SceneBack,
  SceneFront,
  type FaceStyle,
} from '@/shared/print/CardFaces'
import { buildCards } from '@/shared/print/cards'
import { readRelease } from '@/shared/print/release'

import { TableCards } from './TableCards'

// A preset file PHP publishes (backend/presets/), so the faces cannot drift from a real flow. The
// node container mounts it, see compose.yaml.
const presets = import.meta.glob<unknown>('../../../../backend/presets/hard-country.json', {
  eager: true,
  import: 'default',
})
const cards = buildCards(readRelease(Object.values(presets)[0]), { flow: 'the-long-carry' })
const scene = cards.sceneCards[0]
const deck = cards.decks[0]
const entry = deck?.cards[0]

const plain: FaceStyle = { bleed: false, image: null }
// A frame image in the store's shape: any picture does for the story.
const framed: FaceStyle = {
  bleed: false,
  image:
    "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='69' height='94'><rect width='69' height='94' fill='%23e8dcc0'/><rect x='5' y='5' width='59' height='84' fill='none' stroke='%238a6d3b' stroke-width='1'/></svg>",
}

function Faces({ face, width }: { face: FaceStyle; width: string }) {
  if (scene === undefined || deck === undefined || entry === undefined) {
    return <p>The preset has no scene card or deck.</p>
  }
  return (
    <TableCards>
      <div className="flex flex-wrap gap-4" style={{ ['--card-width' as string]: width }}>
        <SceneFront card={scene} face={face} />
        <SceneBack card={scene} face={face} />
        <DeckFront deck={deck} card={entry} index={1} face={face} />
        <DeckBack deck={deck} index={1} face={face} />
      </div>
    </TableCards>
  )
}

const meta = {
  title: 'Play/Table/Card faces',
  component: Faces,
  parameters: { layout: 'padded' },
} satisfies Meta<typeof Faces>

export default meta
type Story = StoryObj<typeof meta>

/** The default size of a card on the table. */
export const Plain: Story = { args: { face: plain, width: '12rem' } }

/** With the release's pinned frame image behind the text. */
export const Framed: Story = { args: { face: framed, width: '12rem' } }

/** The same faces bigger, as a zoomed card: text scales with the card. */
export const Large: Story = { args: { face: plain, width: '20rem' } }
