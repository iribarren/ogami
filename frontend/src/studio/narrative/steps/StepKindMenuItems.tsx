import { DropdownMenuItem } from '@/shared/ui/dropdown-menu'

import { StepKindTile } from './stepKindTile'
import { stepKinds, type StepKind } from './stepDraft'

/** Said in the menu: the publisher refuses a suggested choice without one (S21). */
const CHOICE_MENU_HINT = 'a suggested one needs a Skip option'

/**
 * The kinds of step a menu offers, each with its tile: the Add step menu and the "New step…"
 * entry of a Next select. A Scene Type's setup has no Character, which a shop needs: the
 * publisher refuses it there.
 */
export function StepKindMenuItems({
  setup,
  onPick,
}: {
  setup: boolean
  onPick: (kind: StepKind) => void
}) {
  return stepKinds
    .filter((option) => !(setup && option === 'shop'))
    .map((option) => (
      <DropdownMenuItem
        key={option}
        aria-label={option}
        aria-description={option === 'choice' ? CHOICE_MENU_HINT : undefined}
        onSelect={() => {
          onPick(option)
        }}
      >
        <StepKindTile kind={option} />
        {option}
        {option === 'choice' && (
          // Said before the step is added; the name stays "choice" for assistive technology.
          <span className="ml-2 text-xs text-muted-foreground">{CHOICE_MENU_HINT}</span>
        )}
      </DropdownMenuItem>
    ))
}
