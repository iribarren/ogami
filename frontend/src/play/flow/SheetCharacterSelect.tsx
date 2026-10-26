import { useId } from 'react'

import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import type { Campaign } from '../campaigns/useCampaigns'

/**
 * The control of a choice option or prompt that changes a Character's sheet: the player names
 * the Character from the campaign. The step is answered with the id; the campaign without a
 * Character has nobody to name, so it says so.
 */
export function SheetCharacterSelect({
  campaign,
  value,
  onChange,
}: {
  campaign: Campaign
  value: string
  onChange: (characterId: string) => void
}) {
  const id = useId()

  if (campaign.characters.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        This changes a Character's sheet, and the campaign has no Character yet.
      </p>
    )
  }

  return (
    <div className="space-y-1">
      <Label htmlFor={id}>Character</Label>
      <NativeSelect
        id={id}
        value={value}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      >
        {campaign.characters.length > 1 && <option value="">Choose a Character</option>}
        {campaign.characters.map(({ id: characterId, name }) => (
          <option key={characterId} value={characterId}>
            {name}
          </option>
        ))}
      </NativeSelect>
    </div>
  )
}
