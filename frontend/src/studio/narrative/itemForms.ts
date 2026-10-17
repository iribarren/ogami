import type { ItemForm } from './ItemDialog'
import { FactSlotFields, SceneTypeFields, TagFields, TrackerFields } from './itemFields'
import {
  factSlotToDraft,
  factSlotToItem,
  newLabelled,
  newTracker,
  tagToDraft,
  tagToItem,
  trackerToDraft,
  trackerToItem,
  type LabelledDraft,
  type TrackerDraft,
} from './narrativeDraft'
import { NewFlowFields } from './flows/FlowFields'
import { newFlow, newFlowToItem, type NewFlowDraft } from './flows/flowDraft'
import { newSceneTypeToItem, type NewSceneTypeDraft } from './sceneTypes/sceneTypeDraft'

export const trackerForm: ItemForm<TrackerDraft> = {
  noun: 'tracker',
  section: 'trackers',
  blank: newTracker,
  read: trackerToDraft,
  write: trackerToItem,
  Fields: TrackerFields,
}

export const tagForm: ItemForm<LabelledDraft> = {
  noun: 'tag',
  section: 'tags',
  blank: () => newLabelled(),
  read: tagToDraft,
  write: tagToItem,
  Fields: TagFields,
}

export const factSlotForm: ItemForm<LabelledDraft> = {
  noun: 'fact slot',
  section: 'fact-slots',
  blank: () => newLabelled('text'),
  read: factSlotToDraft,
  write: factSlotToItem,
  Fields: FactSlotFields,
}

/** Creates a Scene Type only: the editor page edits one, so there is nothing to read here. */
export const sceneTypeForm: ItemForm<NewSceneTypeDraft> = {
  noun: 'Scene Type',
  section: 'scene-types',
  blank: () => ({ key: '', name: '', purpose: '' }),
  read: () => null,
  write: newSceneTypeToItem,
  Fields: SceneTypeFields,
}

/**
 * Creates a flow only: the flow editor edits one, so there is nothing to read here. Its Scene
 * Types come with the draft (`newFlow`), which the tab fills from the read model.
 */
export const flowForm: ItemForm<NewFlowDraft> = {
  noun: 'flow',
  section: 'flows',
  blank: () => newFlow([]),
  read: () => null,
  write: newFlowToItem,
  Fields: NewFlowFields,
}
