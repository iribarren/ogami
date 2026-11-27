import { HOOK_LABELS } from '@/shared/flowDiagram/hookLabels'

import { generatedLine, resolvePlaceholders } from './cards'
import type { PhaseSelection, Release, ReleasePhase } from './release'

export interface FlowSheetHook {
  label: string
  /** One line per step, as a card prints a step. */
  lines: string[]
}

export interface FlowSheetPhase {
  key: string
  name: string
  /** "Played once" or "Repeats". */
  mode: string
  description?: string
  /** How the next Scene Type is picked, with the Scene Types and the table by name. */
  selection: string
  hooks: FlowSheetHook[]
}

/** What the page of a flow says in words: its introduction, then each phase with its hooks. */
export interface FlowSheet {
  name: string
  description?: string
  introduction?: string
  phases: FlowSheetPhase[]
}

function sceneTypeName(release: Release, key: string, where: string): string {
  const sceneType = release.sceneTypes.find((candidate) => candidate.key === key)
  if (sceneType === undefined) {
    throw new Error(`${where} names the Scene Type "${key}", which the release does not have.`)
  }
  return sceneType.name
}

function selectionText(release: Release, selection: PhaseSelection, where: string): string {
  if (selection.rule === 'oracle') {
    const table = release.tables.find((candidate) => candidate.key === selection.table)
    if (table === undefined) {
      throw new Error(
        `${where} draws from the table "${selection.table}", which the release does not have.`,
      )
    }
    return `Each scene is drawn from ${table.name}.`
  }
  const names = selection.sceneTypes.map((key) => sceneTypeName(release, key, where)).join(', ')
  return selection.rule === 'sequence'
    ? `Scenes in order: ${names}.`
    : `You choose the scene from: ${names}.`
}

function phaseSheet(release: Release, flowKey: string, phase: ReleasePhase): FlowSheetPhase {
  const where = `Phase "${phase.key}" of the flow "${flowKey}"`
  return {
    key: phase.key,
    name: phase.name,
    mode: phase.mode === 'loop' ? 'Repeats' : 'Played once',
    description: phase.description,
    selection: selectionText(release, phase.selection, where),
    hooks: phase.hooks.map(({ hook, steps }) => ({
      label: HOOK_LABELS[hook],
      lines: steps.map((step) => {
        const at = `${phase.key}.${hook}.${step.key}`
        return resolvePlaceholders(generatedLine(release, step, at), release, steps, at)
      }),
    })),
  }
}

/**
 * The flow of a release as text: what each phase is, how it picks its scenes and what its hooks
 * ask. Throws, saying what is wrong, for a flow the release does not have, a Scene Type or table
 * it names that the release lacks, or a placeholder that points nowhere.
 */
export function buildFlowSheet(release: Release, flowKey: string): FlowSheet {
  const flow = release.flows.find((candidate) => candidate.key === flowKey)
  if (flow === undefined) {
    throw new Error(`The release has no flow "${flowKey}".`)
  }
  return {
    name: flow.name,
    description: flow.description,
    introduction: flow.introduction,
    phases: flow.phases.map((phase) => phaseSheet(release, flowKey, phase)),
  }
}
