import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import type { Campaign } from '../campaigns/useCampaigns'

export type CampaignCheck = Campaign['checks'][number]

/** What the form asks for; the difficulty is left out when the check declares none or the step fixes it. */
export interface CheckRoll {
  check: string
  /** Set only when the form asks for a Character. */
  character?: string
  difficulty?: number
  situational?: number
}

/** "Pool: Agility + Stealth − Physical d6, success on 6+", or "4d6, success on 6+" for a fixed count. */
function describePool(pool: NonNullable<CampaignCheck['pool']>) {
  const success = `success on ${String(pool.successAt)}+`
  return pool.countText === null
    ? `Pool: ${String(pool.count)}d${String(pool.sides)}, ${success}`
    : `Pool: ${pool.countText.replaceAll(' - ', ' − ')} d${String(pool.sides)}, ${success}`
}

/** The bounds of the Situational modifier (glossary). */
const situationalRange = { min: -100, max: 100 }

/**
 * Rolls a check: the check (a select when there are several), its Check difficulty when it
 * declares one, the Situational modifier, and, when `characters` is given, the Character. A
 * `fixedDifficulty` (a check step that fixes it) is shown, not asked. The form only collects; the
 * caller sends. An empty difficulty is left out, so the check's default applies, and so is a zero modifier.
 */
export function CheckForm({
  checks,
  characters,
  fixedDifficulty = null,
  pending,
  error,
  onSubmit,
}: {
  checks: CampaignCheck[]
  characters?: { id: string; name: string }[]
  fixedDifficulty?: number | null
  pending: boolean
  error?: string
  onSubmit: (roll: CheckRoll) => void
}) {
  const id = useId()
  const [checkKey, setCheckKey] = useState(checks[0]?.key ?? '')
  const check = checks.find(({ key }) => key === checkKey)
  const [difficulty, setDifficulty] = useState(String(check?.difficulty?.default ?? ''))
  const [situational, setSituational] = useState('0')
  // A single Character is preselected; with several, the player chooses.
  const [character, setCharacter] = useState(
    characters?.length === 1 ? (characters[0]?.id ?? '') : '',
  )

  function chooseCheck(key: string) {
    setCheckKey(key)
    setDifficulty(String(checks.find((next) => next.key === key)?.difficulty?.default ?? ''))
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (check === undefined) {
      return
    }
    const roll: CheckRoll = { check: check.key }
    if (characters !== undefined) {
      roll.character = character
    }
    if (check.difficulty !== null && fixedDifficulty === null && difficulty.trim() !== '') {
      roll.difficulty = Number(difficulty)
    }
    // Zero is the server's default, so it is not sent.
    if (situational.trim() !== '' && Number(situational) !== 0) {
      roll.situational = Number(situational)
    }
    onSubmit(roll)
  }

  return (
    <form className="space-y-3" onSubmit={handleSubmit}>
      <fieldset disabled={pending} className="space-y-3">
        {characters !== undefined && (
          <div className="space-y-1">
            <Label htmlFor={`${id}-character`}>Character</Label>
            <NativeSelect
              id={`${id}-character`}
              value={character}
              onChange={(event) => {
                setCharacter(event.target.value)
              }}
            >
              {characters.length > 1 && <option value="">Choose a Character</option>}
              {characters.map(({ id: characterId, name }) => (
                <option key={characterId} value={characterId}>
                  {name}
                </option>
              ))}
            </NativeSelect>
          </div>
        )}
        {checks.length > 1 ? (
          <div className="space-y-1">
            <Label htmlFor={`${id}-check`}>Check</Label>
            <NativeSelect
              id={`${id}-check`}
              value={checkKey}
              onChange={(event) => {
                chooseCheck(event.target.value)
              }}
            >
              {checks.map(({ key, label }) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </NativeSelect>
          </div>
        ) : (
          <p className="font-medium">{check?.label}</p>
        )}
        {check?.hint != null && <p className="text-sm text-muted-foreground">{check.hint}</p>}
        {check?.pool != null && <p className="text-sm">{describePool(check.pool)}</p>}
        {check?.difficulty != null &&
          (fixedDifficulty === null ? (
            <div className="space-y-1">
              <Label htmlFor={`${id}-difficulty`}>{check.difficulty.label}</Label>
              <Input
                id={`${id}-difficulty`}
                type="number"
                step={1}
                min={check.difficulty.min ?? undefined}
                max={check.difficulty.max ?? undefined}
                value={difficulty}
                onChange={(event) => {
                  setDifficulty(event.target.value)
                }}
              />
            </div>
          ) : (
            <p className="text-sm">
              {check.difficulty.label}: <strong>{fixedDifficulty}</strong>
            </p>
          ))}
        <div className="space-y-1">
          <Label htmlFor={`${id}-situational`}>
            {check?.pool != null ? 'Extra dice' : 'Situational modifier'}
          </Label>
          <Input
            id={`${id}-situational`}
            type="number"
            step={1}
            {...situationalRange}
            value={situational}
            onChange={(event) => {
              setSituational(event.target.value)
            }}
          />
          {check?.pool != null && (
            <p className="text-sm text-muted-foreground">
              Adds dice to the pool; a negative number takes dice away (from 1 to 100 dice).
            </p>
          )}
        </div>
        <Button
          type="submit"
          disabled={check === undefined || (characters !== undefined && character === '')}
        >
          Roll
        </Button>
      </fieldset>
      {error !== undefined && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </form>
  )
}
