import {
  Check,
  CircleQuestionMark,
  Eye,
  GitBranch,
  List,
  MessageSquare,
  Split,
  UserRound,
} from 'lucide-react'
import type { ReactNode } from 'react'

import { DieShape } from '@/shared/ui/die-shape'
import { IconTile } from '@/shared/ui/icon-tile'

import type { StepKind } from './stepDraft'

/** One icon per kind of step; a roll and a check both roll dice. */
const icons: Record<StepKind, ReactNode> = {
  prompt: <MessageSquare />,
  character: <UserRound />,
  roll: <DieShape sides={20} />,
  condition: <Split />,
  oracle: <Eye />,
  table: <List />,
  choice: <GitBranch />,
  pick: <Check />,
  check: <DieShape sides={20} />,
}

const kinds = Object.keys(icons)

/**
 * The 24px tile of a kind of step. A kind this editor does not know (a stored step) gets a
 * question mark, never the icon of a kind it resembles. Decorative: the kind is named beside it.
 */
export function StepKindTile({ kind }: { kind: string }) {
  const icon = kinds.includes(kind) ? icons[kind as StepKind] : <CircleQuestionMark />

  return <IconTile size="xs">{icon}</IconTile>
}
