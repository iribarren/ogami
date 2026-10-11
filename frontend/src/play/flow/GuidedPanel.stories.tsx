import type { Meta, StoryObj } from '@storybook/react-vite'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import { chosenWay } from '../journal/fixtures'
import { CampaignPage } from '../campaigns/CampaignPage'
import {
  atStep,
  atTheScenePick,
  choiceStep,
  guided,
  inOpenPlay,
  oracleStep,
  promptStep,
  rollStep,
} from './fixtures'
import type { FlowRun } from './useFlowRun'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

/** The Play screen of the lost mine along the Flow "tour", the FlowRun being `flowRun`. */
function guidedApi(flowRun: FlowRun) {
  const path = `/api/campaigns/${lostMineCampaign.id}`
  return {
    [`GET ${path}`]: json(guided(flowRun)),
    [`GET ${path}/journal`]: json([chosenWay]),
  }
}

const meta = {
  title: 'Play/Guided play',
  component: CampaignPage,
  args: { campaignId: lostMineCampaign.id },
  parameters: { api: guidedApi(atStep(promptStep)) },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof CampaignPage>

export default meta
type Story = StoryObj<typeof meta>

export const PromptStep: Story = {}

export const OracleStep: Story = { parameters: { api: guidedApi(atStep(oracleStep)) } }

export const ChoiceStep: Story = { parameters: { api: guidedApi(atStep(choiceStep)) } }

export const RollStep: Story = { parameters: { api: guidedApi(atStep(rollStep)) } }

export const MandatoryStep: Story = {
  parameters: { api: guidedApi(atStep({ ...promptStep, mandatory: true })) },
}

export const PlayerPicksTheSceneType: Story = { parameters: { api: guidedApi(atTheScenePick()) } }

export const OraclePicksTheSceneType: Story = {
  parameters: {
    api: guidedApi(atTheScenePick({ rule: 'oracle', cards: [], table: 'weather' })),
  },
}

export const ForcedSceneType: Story = {
  parameters: {
    api: guidedApi(
      atTheScenePick({
        cards: [{ key: 'tour', name: 'Tour', purpose: 'A tour.', oracles: [] }],
        forced: true,
      }),
    ),
  },
}

export const OpenPlay: Story = { parameters: { api: guidedApi(inOpenPlay) } }

export const Paused: Story = {
  parameters: { api: guidedApi({ ...atStep(promptStep), status: 'paused' }) },
}

export const WaitingForASession: Story = {
  parameters: { api: guidedApi(atStep(promptStep, { step: null, waitsForSession: true })) },
}

export const Complete: Story = {
  parameters: {
    api: guidedApi(
      atStep(promptStep, {
        status: 'completed',
        step: null,
        progress: null,
        next: 'Flow complete',
      }),
    ),
  },
}
