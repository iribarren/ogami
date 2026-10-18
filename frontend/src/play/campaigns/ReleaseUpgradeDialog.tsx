import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import {
  useReleaseUpgradePreview,
  useUpgradeCampaignRelease,
  type ReleaseUpgradePlan,
  type UpgradeFieldChange,
  type UpgradeTrackerChange,
} from './useReleaseUpgrade'
import { useGameSystems, type Campaign } from './useCampaigns'

/**
 * The "Upgrade to vN" button of the campaign header, only when the GameSystem's latest release
 * is newer than the pinned one. Nothing is offered while the GameSystems load or fail to load:
 * the preview and the server decide again anyway.
 */
export function ReleaseUpgradeAction({ campaign }: { campaign: Campaign }) {
  const gameSystems = useGameSystems()
  const { gameSystemKey, version } = campaign.pinnedRelease
  const latest = gameSystems.data?.find((system) => system.gameSystemKey === gameSystemKey)

  if (latest === undefined || latest.version <= version) {
    return null
  }

  return <ReleaseUpgradeDialog campaign={campaign} latestVersion={latest.version} />
}

/**
 * Previews what upgrading the campaign to the latest release changes and asks to confirm. The
 * confirmation sends the previewed version and fingerprint, so the server applies exactly what
 * was shown; when it says the upgrade changed, the preview is loaded again and the player
 * confirms the new one.
 */
function ReleaseUpgradeDialog({
  campaign,
  latestVersion,
}: {
  campaign: Campaign
  latestVersion: number
}) {
  const [open, setOpen] = useState(false)
  const preview = useReleaseUpgradePreview(campaign.id, open)
  const upgrade = useUpgradeCampaignRelease(campaign.id)
  const plan = preview.data?.plan ?? null

  const onOpenChange = (next: boolean) => {
    setOpen(next)
    upgrade.reset()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button type="button" variant="outline" size="sm">
          Upgrade to v{latestVersion}
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            Upgrade {campaign.name} to v{plan?.to.version ?? latestVersion}
          </DialogTitle>
          <DialogDescription>
            Review what the new release changes in this campaign. Nothing changes until you confirm.
          </DialogDescription>
        </DialogHeader>
        {preview.isPending && <p className="text-muted-foreground">Loading the upgrade…</p>}
        {preview.isError && (
          <p role="alert" className="text-sm text-destructive">
            {preview.error.message}
          </p>
        )}
        {preview.data?.upToDate === true && <p>This campaign already plays the latest release.</p>}
        {plan !== null && <UpgradePlan plan={plan} />}
        {upgrade.isError && (
          <p role="alert" className="text-sm text-destructive">
            {upgrade.error.message}
          </p>
        )}
        <div className="flex flex-wrap justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Cancel
            </Button>
          </DialogClose>
          {plan !== null && (
            <Button
              type="button"
              // A preview being reloaded is not the one to confirm.
              disabled={upgrade.isPending || preview.isFetching}
              onClick={() => {
                upgrade.mutate(
                  { toVersion: plan.to.version, fingerprint: plan.fingerprint },
                  {
                    onSuccess: () => {
                      setOpen(false)
                    },
                  },
                )
              }}
            >
              Confirm upgrade
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}

/** The sections of a plan, each omitted when it has nothing to list. */
function UpgradePlan({ plan }: { plan: ReleaseUpgradePlan }) {
  const characterChanges = plan.characters.filter(({ changes }) => changes.length > 0)
  const droppedTags = [
    ...plan.characters
      .filter(({ droppedTags }) => droppedTags.length > 0)
      .map(({ id, name, droppedTags }) => ({ id, name: `${name} (Character)`, tags: droppedTags })),
    ...plan.npcs.map(({ id, name, tags }) => ({ id, name: `${name} (NPC)`, tags })),
    ...plan.threads.map(({ id, name, tags }) => ({ id, name: `${name} (Thread)`, tags })),
  ]

  return (
    <div className="space-y-4">
      <p>{`${plan.from.name} v${String(plan.from.version)} → v${String(plan.to.version)}`}</p>
      {characterChanges.length > 0 && (
        <PlanSection title="Characters">
          {characterChanges.map((character) => (
            <li key={character.id}>
              <span className="font-medium">{character.name}</span>
              <ul className="ml-4 list-disc">
                {character.changes.map((change) => (
                  <li key={change.fieldKey}>{fieldChangeText(change)}</li>
                ))}
              </ul>
            </li>
          ))}
        </PlanSection>
      )}
      {droppedTags.length > 0 && (
        <PlanSection title="Tags">
          {droppedTags.map(({ id, name, tags }) => (
            <li key={id}>
              <span className="font-medium">{name}</span> loses {tags.join(', ')}
            </li>
          ))}
        </PlanSection>
      )}
      {plan.trackers.length > 0 && (
        <PlanSection title="Trackers">
          {plan.trackers.map((tracker) => (
            <li key={tracker.trackerKey}>{trackerChangeText(tracker)}</li>
          ))}
        </PlanSection>
      )}
      {plan.facts.length > 0 && (
        <PlanSection title="Campaign facts">
          {plan.facts.map((fact) => (
            <li key={fact.id}>
              <span className="font-medium">{fact.label}</span> loses its slot ({fact.slotKey}) and
              stays as a free fact
            </li>
          ))}
        </PlanSection>
      )}
      {plan.flow.restarted && (
        <PlanSection title="Guidance">
          <li>
            Guidance restarts at the first phase of the {plan.flow.toFlowKey ?? 'free play'} Flow
            {plan.flow.fromFlowKey !== plan.flow.toFlowKey && plan.flow.fromFlowKey !== null
              ? ` (it was ${plan.flow.fromFlowKey})`
              : ''}
            .
          </li>
        </PlanSection>
      )}
      <p className="text-muted-foreground">Nothing else changes.</p>
    </div>
  )
}

function PlanSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section aria-label={title} className="space-y-1">
      <h3 className="font-heading font-semibold">{title}</h3>
      <ul className="space-y-1">{children}</ul>
    </section>
  )
}

type FieldValue = UpgradeFieldChange['before']

/** A stored value as a player reads it: a track as "current/max", a list joined, nothing as "none". */
function valueText(value: FieldValue): string {
  if (value === null) {
    return 'none'
  }
  if (typeof value === 'object' && !Array.isArray(value)) {
    return `${String(value.current)}/${String(value.max)}`
  }
  if (Array.isArray(value)) {
    return value.length === 0 ? 'empty' : value.join(', ')
  }
  return value === '' ? 'empty' : String(value)
}

function fieldChangeText({ fieldName, kind, before, after }: UpgradeFieldChange) {
  return `${fieldName} (${kind}): ${valueText(before)} → ${valueText(after)}`
}

function trackerChangeText({ name, kind, before, after }: UpgradeTrackerChange) {
  return kind === 'removed'
    ? `${name} (removed): ${String(before)}`
    : `${name} (clamped): ${String(before)} → ${String(after)}`
}
