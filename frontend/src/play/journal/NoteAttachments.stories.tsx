import type { Meta, StoryObj } from '@storybook/react-vite'

import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import {
  fateDouble,
  fateDoubleEvent,
  keepThreeRoll,
  lockedDoorAnswer,
  noteAttaching,
  skillCheckEntry,
  stormyWeather,
} from './fixtures'
import { JournalEntryView } from './JournalEntryView'
import { NoteAttachments } from './NoteAttachments'

const attached = [stormyWeather, lockedDoorAnswer, skillCheckEntry, fateDouble, fateDoubleEvent]
const note = noteAttaching([
  stormyWeather.id,
  lockedDoorAnswer.id,
  skillCheckEntry.id,
  fateDouble.id,
])

const meta = {
  title: 'Play/NoteAttachments',
  component: NoteAttachments,
  args: {
    campaignId: lostMineCampaign.id,
    note,
    entries: [keepThreeRoll, ...attached, note],
    tables: lostMineCampaign.oracleTables,
    tags: lostMineCampaign.tags,
    current: false,
  },
  decorators: [
    (_Story, { args }) => (
      <StoryProviders handlers={{}}>
        <div className="max-w-xl">
          <JournalEntryView entry={args.note} tags={args.tags}>
            <NoteAttachments {...args} />
          </JournalEntryView>
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof NoteAttachments>

export default meta
type Story = StoryObj<typeof meta>

/** A note with a table result, a fate answer, a check and a fate question with its random event. */
export const WithAttachments: Story = {}

/** An attached fate question whose random event is still to roll, in the current scene. */
export const RandomEventToRoll: Story = {
  args: {
    note: noteAttaching([fateDouble.id]),
    entries: [fateDouble, noteAttaching([fateDouble.id])],
    current: true,
  },
}
