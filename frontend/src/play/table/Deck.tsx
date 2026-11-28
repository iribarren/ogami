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

import type { Campaign } from '../campaigns/useCampaigns'
import type { FlowRunCommand, ScenePick, useFlowRunCommand } from '../flow/useFlowRun'
import { CardBack } from './CardBack'

type Command = ReturnType<typeof useFlowRunCommand>
type OfferedCard = ScenePick['cards'][number]

const pileClass =
  'w-20 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring enabled:cursor-pointer disabled:opacity-60 motion-safe:enabled:transition-transform motion-safe:enabled:hover:-translate-y-0.5'

/** An offered Scene Type, face up at reading size: what it is, what it is for, its tips. */
function OfferedCardFace({ card, forced }: { card: OfferedCard; forced: boolean }) {
  return (
    <article className="space-y-2 rounded-xl border bg-card p-4 text-base text-card-foreground shadow-sm">
      <h3 className="font-heading text-lg font-semibold">{card.name}</h3>
      {forced && <p className="text-sm text-muted-foreground">Forced for this scene</p>}
      <p>{card.purpose}</p>
      {card.tips !== null && (
        <p className="text-sm whitespace-pre-line text-muted-foreground">{card.tips}</p>
      )}
    </article>
  )
}

/** A face and the button that plays it. */
function PlayableCard({
  card,
  forced,
  pending,
  onPlay,
}: {
  card: OfferedCard
  forced: boolean
  pending: boolean
  onPlay: (command: FlowRunCommand) => void
}) {
  return (
    <div className="space-y-2">
      <OfferedCardFace card={card} forced={forced} />
      <Button
        type="button"
        className="w-full"
        disabled={pending}
        onClick={() => {
          onPlay({ type: 'pick', sceneType: card.key })
        }}
      >
        Play {card.name}
      </Button>
    </div>
  )
}

/**
 * Where the next card of a sequence or a forced one shows: beside the deck on a wide table, above
 * the dock on a narrow one, where the deck itself is only a small pile.
 */
function Reveal({ children, animation }: { children: React.ReactNode; animation: string }) {
  return (
    <div
      className={`${animation} fixed inset-x-3 bottom-20 z-20 mx-auto max-w-xs rounded-xl border bg-popover p-3 shadow-lg @3xl:static @3xl:inset-auto @3xl:max-w-none @3xl:border-0 @3xl:bg-transparent @3xl:p-0 @3xl:shadow-none`}
    >
      {children}
    </div>
  )
}

/**
 * The deck of the current phase, a picture of the phase's selection rule (D4 of play-tabletop):
 * no shuffle, no draw state. The offered cards come from `flowRun.pick`, and every move is the
 * same `pick` / `pickByRoll` command the Scene pick card sends.
 */
export function Deck({
  campaign,
  command,
  backImage,
}: {
  campaign: Campaign
  command: Command
  backImage: string | null
}) {
  const { flowRun } = campaign
  const pick = flowRun?.status === 'active' ? flowRun.pick : null
  const [open, setOpen] = useState(false)
  const [turned, setTurned] = useState(false)
  const pending = command.isPending
  const send = (next: FlowRunCommand) => {
    command.mutate(next, {
      onSuccess: () => {
        setOpen(false)
        setTurned(false)
      },
    })
  }
  const failed =
    command.error !== null &&
    (command.variables.type === 'pick' || command.variables.type === 'pickByRoll')
  const alert = failed && (
    <p role="alert" className="text-xs text-destructive @3xl:text-sm">
      {command.error.message}
    </p>
  )
  const caption = <span className="text-xs text-muted-foreground @3xl:text-sm">Deck</span>

  if (pick === null) {
    return (
      <div className="flex flex-col items-center gap-1">
        <button
          type="button"
          disabled
          aria-label={`Deck, face down. Next: ${flowRun?.next ?? 'no guided flow'}`}
          className={pileClass}
        >
          <CardBack image={backImage} />
        </button>
        {caption}
      </div>
    )
  }

  if (pick.rule === 'oracle' && pick.cards.length === 0) {
    const table = campaign.oracleTables.find(({ key }) => key === pick.table)?.name ?? pick.table
    return (
      <div className="flex flex-col items-center gap-1">
        <button
          type="button"
          disabled={pending}
          aria-label={`Roll on ${table ?? 'the table'}`}
          className={pileClass}
          onClick={() => {
            send({ type: 'pickByRoll' })
          }}
        >
          <CardBack image={backImage} />
        </button>
        {caption}
        {alert}
      </div>
    )
  }

  const [only] = pick.cards
  if (pick.rule === 'sequence' && !pick.forced && pick.cards.length === 1 && only !== undefined) {
    return (
      <div className="flex flex-col items-center gap-1">
        <button
          type="button"
          aria-label="Turn the next card"
          aria-expanded={turned}
          className={pileClass}
          onClick={() => {
            setTurned(!turned)
          }}
        >
          <CardBack image={backImage} />
        </button>
        {caption}
        {turned && (
          <Reveal animation="table-flip">
            <PlayableCard card={only} forced={false} pending={pending} onPlay={send} />
          </Reveal>
        )}
        {alert}
      </div>
    )
  }

  if (pick.forced) {
    return (
      <div className="flex flex-col items-center gap-1">
        <button type="button" disabled aria-label="Deck, one card drawn" className={pileClass}>
          <CardBack image={backImage} />
        </button>
        {caption}
        <Reveal animation="table-slide">
          <div className="space-y-3">
            {pick.cards.map((card) => (
              <PlayableCard key={card.key} card={card} forced pending={pending} onPlay={send} />
            ))}
          </div>
        </Reveal>
        {alert}
      </div>
    )
  }

  return (
    <div className="flex flex-col items-center gap-1">
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogTrigger asChild>
          <button type="button" aria-label="Choose the next Scene Type" className={pileClass}>
            <CardBack image={backImage} />
          </button>
        </DialogTrigger>
        {caption}
        <DialogContent className="max-h-dvh w-screen max-w-none rounded-none text-base sm:w-[min(100vw-2rem,64rem)] sm:rounded-xl">
          <DialogHeader>
            <DialogTitle>Choose the next Scene Type</DialogTitle>
            <DialogDescription>
              The deck offers these cards. Play one to start the scene.
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {pick.cards.map((card) => (
              <PlayableCard
                key={card.key}
                card={card}
                forced={false}
                pending={pending}
                onPlay={send}
              />
            ))}
          </div>
          {alert}
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Close
            </Button>
          </DialogClose>
        </DialogContent>
      </Dialog>
    </div>
  )
}
