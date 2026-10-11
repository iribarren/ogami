import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  campaignKey,
  explainedRefusals,
  type Campaign,
} from '../campaigns/useCampaigns'
import { journalKey } from '../journal/useJournal'

export type FlowRun = NonNullable<Campaign['flowRun']>
export type FlowStep = NonNullable<FlowRun['step']>
export type ScenePick = NonNullable<FlowRun['pick']>
export type CompleteFlowStepRequest = components['schemas']['CompleteFlowStepRequest']

/** What the player can ask of a campaign's FlowRun: one request each. */
export type FlowRunCommand =
  | { type: 'answer'; body: CompleteFlowStepRequest }
  | { type: 'skip'; stepKey: string }
  | { type: 'pick'; sceneType: string }
  | { type: 'pickByRoll' }
  | { type: 'endScene'; sceneNumber: number }
  | { type: 'moveOn'; phase: string }
  | { type: 'pause' }
  | { type: 'resume' }
  | { type: 'endSession' }

/**
 * Runs one FlowRun command: the campaign answer replaces the cached campaign and the journal
 * is refetched, since a step records an entry. Ending the session answers the session, so the
 * campaign is refetched instead. A refusal (409, 422…) is the mutation's error, with the API's
 * message.
 */
export function useFlowRunCommand(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const path = { campaignId }

  return useMutation({
    mutationFn: async (command: FlowRunCommand): Promise<Campaign | null> => {
      const { data, error, response } = await send(command)
      if (data !== undefined) {
        return data === 'ended' ? null : data
      }
      throw campaignError(response, error, explainedRefusals, failures[command.type])
    },
    onSuccess: async (campaign) => {
      if (campaign === null) {
        await queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })
      } else {
        queryClient.setQueryData(campaignKey(campaignId), campaign)
      }
      void queryClient.invalidateQueries({ queryKey: journalKey(campaignId) })
    },
  })

  async function send(command: FlowRunCommand): Promise<{
    data?: Campaign | 'ended'
    error?: { error: string }
    response: Response
  }> {
    const params = { params: { path } }
    switch (command.type) {
      case 'answer':
        return api.POST('/api/campaigns/{campaignId}/flow-run/answer', {
          ...params,
          body: command.body,
        })
      case 'skip':
        return api.POST('/api/campaigns/{campaignId}/flow-run/skip', {
          ...params,
          body: { stepKey: command.stepKey },
        })
      case 'pick':
        return api.POST('/api/campaigns/{campaignId}/flow-run/pick', {
          ...params,
          body: { sceneType: command.sceneType },
        })
      case 'pickByRoll':
        return api.POST('/api/campaigns/{campaignId}/flow-run/pick/roll', params)
      case 'endScene':
        return api.POST('/api/campaigns/{campaignId}/flow-run/end-scene', {
          ...params,
          body: { sceneNumber: command.sceneNumber },
        })
      case 'moveOn':
        return api.POST('/api/campaigns/{campaignId}/flow-run/move-on', {
          ...params,
          body: { phase: command.phase },
        })
      case 'pause':
        return api.POST('/api/campaigns/{campaignId}/flow-run/pause', params)
      case 'resume':
        return api.POST('/api/campaigns/{campaignId}/flow-run/resume', params)
      case 'endSession': {
        // The session answers, not the campaign: the caller refetches the campaign.
        const { data, error, response } = await api.POST(
          '/api/campaigns/{campaignId}/sessions/current/end',
          params,
        )
        return { data: data === undefined ? undefined : 'ended', error, response }
      }
    }
  }
}

const failures: Record<FlowRunCommand['type'], string> = {
  answer: 'Answering the step',
  skip: 'Skipping the step',
  pick: 'Picking the Scene Type',
  pickByRoll: 'Rolling the Scene Type',
  endScene: 'Ending the scene',
  moveOn: 'Moving on',
  pause: 'Pausing the guidance',
  resume: 'Resuming the guidance',
  endSession: 'Ending the session',
}
