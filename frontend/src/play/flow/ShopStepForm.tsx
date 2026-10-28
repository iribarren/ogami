import { useState } from 'react'

import { stacksOf } from '@/shared/sheet/sheetValues'
import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'

import type { Campaign } from '../campaigns/useCampaigns'
import { effectiveCharacterId } from './effectiveCharacterId'
import { SheetCharacterSelect } from './SheetCharacterSelect'
import type { FlowStep } from './useFlowRun'

type Shop = NonNullable<FlowStep['shop']>
interface BuyLine {
  gear: string
  quantity: number
}
interface SellLine {
  field: string
  gear: string
  quantity: number
}

const maxQuantity = 9999

/** A quantity draft as a count: empty is none; anything but a whole number in 0..max is null (invalid). */
function parseQuantity(draft: string | undefined, max: number): number | null {
  if (draft === undefined || draft.trim() === '') {
    return 0
  }
  const quantity = Number(draft)
  return Number.isInteger(quantity) && quantity >= 0 && quantity <= max ? quantity : null
}

/** What the shop pays for one unit: the sell rate (percent) of its cost, rounded down. */
function sellPrice(cost: number, sellRate: number): number {
  return Math.floor((cost * sellRate) / 100)
}

/**
 * The control of a `shop` step (ADR 0045): the player names the Character, fills a cart of the gear
 * on sale and, when the shop buys, of the Character's catalog stacks to sell, sees what it spends,
 * earns and leaves, and completes the step with one transaction. An empty cart leaves the shop. The
 * balance warning is a hint; the server decides and its refusal shows with the cart untouched.
 */
export function ShopStepForm({
  campaign,
  shop,
  pending,
  onComplete,
}: {
  campaign: Campaign
  shop: Shop
  pending: boolean
  onComplete: (characterId: string, buy: BuyLine[], sell: SellLine[]) => void
}) {
  const [chosenCharacterId, setCharacterId] = useState('')
  const [buyDrafts, setBuyDrafts] = useState<Record<string, string>>({})
  const [sellDrafts, setSellDrafts] = useState<Record<string, string>>({})
  const characterId = effectiveCharacterId(campaign, chosenCharacterId)
  const { sheet, gear } = campaign.pinnedRelease
  const character = campaign.characters.find(({ id }) => id === characterId)
  const moneyField = sheet.find(({ key }) => key === shop.currency.field)
  const moneyLabel = moneyField?.label ?? shop.currency.field
  const floor = moneyField?.min ?? 0
  const money = (amount: number) =>
    shop.currency.unit === null ? String(amount) : `${String(amount)} ${shop.currency.unit}`

  // A Character with no stored value starts where the server reads it: the default, else 0 moved inside min..max.
  const stored = character?.values[shop.currency.field]
  const current =
    typeof stored === 'number'
      ? stored
      : (moneyField?.default ??
        Math.max(moneyField?.min ?? -Infinity, Math.min(0, moneyField?.max ?? Infinity)))

  // The Character's catalog stacks the shop can buy: free stacks have no price and are never sold.
  const sellable =
    shop.sellRate === null || character === undefined
      ? []
      : sheet
          .filter(({ type }) => type === 'inventory')
          .flatMap((field) =>
            stacksOf(character.values[field.key]).flatMap((stack, index) => {
              const entry = 'gear' in stack ? gear.find(({ key }) => key === stack.gear) : undefined
              return 'gear' in stack && entry !== undefined && shop.sellRate !== null
                ? [
                    {
                      // Two stacks of one gear are two lines: the position tells them apart.
                      id: `${field.key}:${String(index)}`,
                      field,
                      entry,
                      held: stack.quantity,
                      price: sellPrice(entry.cost, shop.sellRate),
                    },
                  ]
                : []
            }),
          )

  const buyQuantities = shop.onSale.map((entry) => ({
    entry,
    quantity: parseQuantity(buyDrafts[entry.key], maxQuantity),
  }))
  const sellQuantities = sellable.map((line) => ({
    line,
    quantity: parseQuantity(sellDrafts[line.id], Math.min(maxQuantity, line.held)),
  }))
  const invalid =
    buyQuantities.some(({ quantity }) => quantity === null) ||
    sellQuantities.some(({ quantity }) => quantity === null)

  const spend = buyQuantities.reduce(
    (sum, { entry, quantity }) => sum + entry.cost * (quantity ?? 0),
    0,
  )
  // The server takes one sell line per field and gear, drawn from the first stack of that gear, so
  // lines of duplicate stacks are merged: quantities summed, capped at that first stack.
  const sold = sellQuantities.reduce<
    { line: (typeof sellable)[number]; quantity: number; typed: number }[]
  >((merged, { line, quantity }) => {
    const same = merged.find(
      (other) => other.line.field.key === line.field.key && other.line.entry.key === line.entry.key,
    )
    if (same === undefined) {
      return [...merged, { line, quantity: quantity ?? 0, typed: quantity ?? 0 }]
    }
    same.typed += quantity ?? 0
    same.quantity = Math.min(same.line.held, same.typed)
    return merged
  }, [])
  // A later duplicate stack cannot sell what the first one lacks: say so, once per group, on the
  // line whose quantity pushes the group's typed total past the first stack. A line nobody typed on
  // never pushes it, so it never shows the hint.
  const cappedHint = (line: (typeof sellable)[number]) => {
    const group = sold.find(
      (other) => other.line.field.key === line.field.key && other.line.entry.key === line.entry.key,
    )
    if (group === undefined || group.line === line) {
      return null
    }
    let total = 0
    const pushing = sellQuantities.find((other) => {
      if (other.line.field.key !== line.field.key || other.line.entry.key !== line.entry.key) {
        return false
      }
      total += other.quantity ?? 0
      return total > group.line.held
    })
    return pushing?.line === line
      ? `Only ${String(group.line.held)} ${group.line.entry.name} can be sold: the shop takes them from the first stack.`
      : null
  }
  const earn = sold.reduce((sum, { line, quantity }) => sum + line.price * quantity, 0)
  const balance = current - spend + earn
  // The server refuses only a balance under the floor that is also lower than before: a Character
  // already under it may still gain money.
  const belowFloor = balance < floor && balance < current
  const empty =
    !invalid &&
    buyQuantities.every(({ quantity }) => quantity === 0) &&
    sellQuantities.every(({ quantity }) => quantity === 0)

  function submit() {
    onComplete(
      characterId,
      buyQuantities.flatMap(({ entry, quantity }) =>
        quantity === null || quantity === 0 ? [] : [{ gear: entry.key, quantity }],
      ),
      sold.flatMap(({ line, quantity }) =>
        quantity === 0 ? [] : [{ field: line.field.key, gear: line.entry.key, quantity }],
      ),
    )
  }

  return (
    <form
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault()
        submit()
      }}
    >
      <SheetCharacterSelect
        campaign={campaign}
        value={characterId}
        onChange={(next) => {
          setCharacterId(next)
          // Another Character holds other stacks: a sale drafted for the last one means nothing.
          setSellDrafts({})
        }}
      />
      <section aria-label="Buy" className="space-y-1">
        <h5 className="text-sm font-medium">Buy</h5>
        <ul className="space-y-2">
          {shop.onSale.map((entry) => (
            <li key={entry.key} className="flex flex-wrap items-center gap-2">
              <span className="min-w-0 flex-1 break-words">
                <span className="font-medium">{entry.name}</span> · {money(entry.cost)}
                <span className="text-xs text-muted-foreground">
                  {' '}
                  · {entry.slots} {entry.slots === 1 ? 'slot' : 'slots'}
                  {entry.category !== null && ` · ${entry.category}`}
                </span>
                {entry.description !== null && (
                  <span className="block text-xs text-muted-foreground">{entry.description}</span>
                )}
              </span>
              <QuantityInput
                label={`Buy ${entry.name}`}
                draft={buyDrafts[entry.key] ?? ''}
                valid={parseQuantity(buyDrafts[entry.key], maxQuantity) !== null}
                onChange={(draft) => {
                  setBuyDrafts((drafts) => ({ ...drafts, [entry.key]: draft }))
                }}
              />
            </li>
          ))}
        </ul>
      </section>
      {shop.sellRate !== null && (
        <section aria-label="Sell" className="space-y-1">
          <h5 className="text-sm font-medium">Sell</h5>
          {sellable.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {character === undefined
                ? 'Choose a Character to see what they can sell.'
                : 'This Character has nothing the shop buys.'}
            </p>
          ) : (
            <ul className="space-y-2">
              {sellable.map((line) => (
                <li key={line.id} className="flex flex-wrap items-center gap-2">
                  <span className="min-w-0 flex-1 break-words">
                    <span className="font-medium">{line.entry.name}</span> · pays{' '}
                    {money(line.price)} each
                    <span className="text-xs text-muted-foreground">
                      {' '}
                      · {line.field.label}, holds {line.held}
                    </span>
                  </span>
                  <QuantityInput
                    label={`Sell ${line.entry.name} from ${line.field.label}`}
                    draft={sellDrafts[line.id] ?? ''}
                    max={Math.min(maxQuantity, line.held)}
                    valid={
                      parseQuantity(sellDrafts[line.id], Math.min(maxQuantity, line.held)) !== null
                    }
                    onChange={(draft) => {
                      setSellDrafts((drafts) => ({ ...drafts, [line.id]: draft }))
                    }}
                  />
                  {cappedHint(line) !== null && (
                    <p className="basis-full text-xs text-destructive">{cappedHint(line)}</p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 text-sm tabular-nums" aria-label="Totals">
        <dt className="text-muted-foreground">Spend</dt>
        <dd>{money(spend)}</dd>
        <dt className="text-muted-foreground">Earn</dt>
        <dd>{money(earn)}</dd>
        <dt className="text-muted-foreground">{moneyLabel} now</dt>
        <dd>{money(current)}</dd>
        <dt className="text-muted-foreground">{moneyLabel} after</dt>
        <dd className={belowFloor ? 'font-medium text-destructive' : undefined}>
          {money(balance)}
        </dd>
      </dl>
      {invalid && (
        <p role="alert" className="text-sm text-destructive">
          Every quantity must be a whole number, at most what is on sale or held.
        </p>
      )}
      {belowFloor && !invalid && (
        <p role="alert" className="text-sm text-destructive">
          Not enough {moneyLabel}: this would leave {money(balance)}, under {money(floor)}.
        </p>
      )}
      <Button type="submit" disabled={pending || characterId === '' || invalid || belowFloor}>
        {empty ? 'Leave the shop' : 'Confirm'}
      </Button>
    </form>
  )
}

/** A quantity box that may stay empty (none) while the player types; an invalid draft is flagged. */
function QuantityInput({
  label,
  draft,
  valid,
  max = maxQuantity,
  onChange,
}: {
  label: string
  draft: string
  valid: boolean
  max?: number
  onChange: (draft: string) => void
}) {
  return (
    <Input
      type="number"
      className="w-20"
      aria-label={label}
      aria-invalid={!valid}
      min={0}
      max={max}
      placeholder="0"
      value={draft}
      onChange={(event) => {
        onChange(event.target.value)
      }}
    />
  )
}
