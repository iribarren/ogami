import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Checkbox } from '@/shared/ui/checkbox'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { useTransferCampaign } from './useCampaignTransfer'
import { MAX_NAME_LENGTH, PLAY_FREELY, useGameSystems, type Campaign } from './useCampaigns'

/**
 * The "Continue in a new campaign" button of the campaign header (ADR 0046). A campaign on an
 * older release is told to upgrade first; while the GameSystems load or fail to load the form
 * is offered anyway, as the server refuses an outdated source itself.
 */
export function CampaignTransferAction({ campaign }: { campaign: Campaign }) {
  const gameSystems = useGameSystems()
  const { gameSystemKey, version } = campaign.pinnedRelease
  const latest = gameSystems.data?.find((system) => system.gameSystemKey === gameSystemKey)
  const outdated = latest !== undefined && latest.version > version

  return <CampaignTransferDialog campaign={campaign} outdated={outdated} />
}

function CampaignTransferDialog({ campaign, outdated }: { campaign: Campaign; outdated: boolean }) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button" variant="outline" size="sm">
          Continue in a new campaign
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Continue {campaign.name} in a new campaign</DialogTitle>
          <DialogDescription>
            The new campaign keeps the NPCs, Threads and Campaign facts, and the Characters you
            choose. Trackers start over and there are no sessions. {campaign.name} stays as it is.
          </DialogDescription>
        </DialogHeader>
        {outdated ? (
          <>
            <p role="alert">
              Upgrade the release first: this campaign plays an older release than the latest one.
            </p>
            <div className="flex justify-end">
              <DialogClose asChild>
                <Button type="button" variant="outline">
                  Close
                </Button>
              </DialogClose>
            </div>
          </>
        ) : (
          <TransferForm campaign={campaign} />
        )}
      </DialogContent>
    </Dialog>
  )
}

/** The Flow to play first: the release's default one, else playing freely. */
function defaultFlowKey(flows: Campaign['flows']) {
  return flows.find((flow) => flow.default)?.key ?? PLAY_FREELY
}

/**
 * Name, Flow and Characters of the new campaign. The name follows "<source name>: <Flow name>"
 * until the player types one. Mounted only while the dialog is open, so it starts over each time.
 */
function TransferForm({ campaign }: { campaign: Campaign }) {
  const transfer = useTransferCampaign(campaign.id)
  const [flowKey, setFlowKey] = useState(defaultFlowKey(campaign.flows))
  const [typedName, setTypedName] = useState<string | null>(null)
  const [characterIds, setCharacterIds] = useState(
    () => new Set(campaign.characters.map((character) => character.id)),
  )
  const nameId = useId()
  const flowId = useId()
  const chosenFlow = campaign.flows.find((flow) => flow.key === flowKey)
  const suggestedName = `${campaign.name}: ${chosenFlow?.name ?? 'Free play'}`.slice(
    0,
    MAX_NAME_LENGTH,
  )
  const name = typedName ?? suggestedName

  function toggleCharacter(id: string, checked: boolean) {
    setCharacterIds((current) => {
      const next = new Set(current)
      if (checked) {
        next.add(id)
      } else {
        next.delete(id)
      }
      return next
    })
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    transfer.mutate({
      name,
      flowKey: chosenFlow?.key ?? null,
      // In the order of the sheet, whatever order they were unchecked and checked in.
      characterIds: campaign.characters
        .filter((character) => characterIds.has(character.id))
        .map((character) => character.id),
    })
  }

  return (
    <form aria-label="Continue in a new campaign" className="space-y-4" onSubmit={handleSubmit}>
      <div className="space-y-2">
        <Label htmlFor={nameId}>New campaign name</Label>
        <Input
          id={nameId}
          value={name}
          maxLength={MAX_NAME_LENGTH}
          autoComplete="off"
          onChange={(event) => {
            setTypedName(event.target.value)
          }}
        />
      </div>
      {campaign.flows.length > 0 && (
        <div className="space-y-2">
          <Label htmlFor={flowId}>Flow</Label>
          <NativeSelect
            id={flowId}
            value={flowKey}
            onChange={(event) => {
              setFlowKey(event.target.value)
            }}
          >
            <option value={PLAY_FREELY}>Play freely</option>
            {campaign.flows.map((flow) => (
              <option key={flow.key} value={flow.key}>
                {flow.name}
              </option>
            ))}
          </NativeSelect>
        </div>
      )}
      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Characters to bring along</legend>
        {campaign.characters.length === 0 && (
          <p className="text-sm text-muted-foreground">This campaign has no Characters.</p>
        )}
        {campaign.characters.map((character) => (
          <div key={character.id} className="flex items-center gap-2">
            <Checkbox
              id={`${nameId}-${character.id}`}
              checked={characterIds.has(character.id)}
              onCheckedChange={(checked) => {
                toggleCharacter(character.id, checked === true)
              }}
            />
            <Label htmlFor={`${nameId}-${character.id}`}>{character.name}</Label>
          </div>
        ))}
      </fieldset>
      {transfer.error && (
        <p role="alert" className="text-sm text-destructive">
          {transfer.error.message}
        </p>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        <DialogClose asChild>
          <Button type="button" variant="outline">
            Cancel
          </Button>
        </DialogClose>
        <Button type="submit" disabled={name.trim() === '' || transfer.isPending}>
          Create new campaign
        </Button>
      </div>
    </form>
  )
}
