import { useId, useState, type SubmitEvent } from 'react'

import { CAMPAIGN_THEME_NAMES } from '@/shared/theme/themes'
import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { Textarea } from '@/shared/ui/textarea'

import {
  StudioError,
  useEditMetadata,
  type EditMetadataRequest,
  type GameSystem,
} from './useGameSystems'

/** The release contract's limit on a name, checked by the server too. */
const MAX_NAME_LENGTH = 100

/** Empty text means "none" for the optional fields. */
const orNull = (text: string) => (text.trim() === '' ? null : text)

/**
 * The metadata of a draft: name, description and, from schema version 2 on, credits and theme
 * (schema version 1 has neither, so they are hidden and not sent). Saving sends every field and
 * replaces the cached GameSystem with the answer. A concurrent save (409) is explained with a
 * way to reload the latest version, which `onReload` provides.
 */
export function MetadataForm({
  gameSystem,
  onReload,
}: {
  gameSystem: GameSystem
  onReload: () => void
}) {
  const hasCreditsAndTheme = gameSystem.schemaVersion >= 2
  const [name, setName] = useState(gameSystem.name)
  const [description, setDescription] = useState(gameSystem.description ?? '')
  const [notice, setNotice] = useState(gameSystem.credits?.notice ?? '')
  const [license, setLicense] = useState(gameSystem.credits?.license ?? '')
  const [url, setUrl] = useState(gameSystem.credits?.url ?? '')
  const [theme, setTheme] = useState(gameSystem.theme ?? '')
  const save = useEditMetadata(gameSystem.key)
  // "Saved." describes the last save only: any later edit hides it.
  const [edited, setEdited] = useState(false)
  const nameId = useId()
  const descriptionId = useId()
  const noticeId = useId()
  const licenseId = useId()
  const urlId = useId()
  const themeId = useId()
  // Credits are all or nothing: a license or link without a notice has nothing to qualify.
  const creditsValid = notice.trim() !== '' || (license.trim() === '' && url.trim() === '')
  const valid = name.trim() !== '' && (!hasCreditsAndTheme || creditsValid)
  // A theme the form does not know (the API may grow one) stays selectable instead of vanishing.
  const themes: string[] = [...CAMPAIGN_THEME_NAMES]
  if (theme !== '' && !themes.includes(theme)) {
    themes.push(theme)
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const body: EditMetadataRequest = { name, description: orNull(description) }
    if (hasCreditsAndTheme) {
      body.credits =
        notice.trim() === '' ? null : { notice, license: orNull(license), url: orNull(url) }
      body.theme = orNull(theme)
    }
    setEdited(false)
    save.mutate(body)
  }

  return (
    <form
      aria-label="Metadata"
      className="max-w-xl space-y-4"
      onChange={() => {
        setEdited(true)
      }}
      onSubmit={handleSubmit}
    >
      <div className="space-y-2">
        <Label htmlFor={nameId}>Name</Label>
        <Input
          id={nameId}
          value={name}
          maxLength={MAX_NAME_LENGTH}
          autoComplete="off"
          onChange={(event) => {
            setName(event.target.value)
          }}
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor={descriptionId}>Description</Label>
        <Textarea
          id={descriptionId}
          value={description}
          onChange={(event) => {
            setDescription(event.target.value)
          }}
        />
      </div>
      {hasCreditsAndTheme && (
        <>
          <fieldset className="space-y-4">
            <legend className="font-heading font-semibold">Credits</legend>
            <div className="space-y-2">
              <Label htmlFor={noticeId}>Notice</Label>
              <Textarea
                id={noticeId}
                value={notice}
                aria-invalid={!creditsValid}
                onChange={(event) => {
                  setNotice(event.target.value)
                }}
              />
              {!creditsValid && (
                <p className="text-sm text-destructive">
                  A license or link needs a notice. Clear them, or write the notice.
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor={licenseId}>License (optional)</Label>
              <Input
                id={licenseId}
                value={license}
                autoComplete="off"
                onChange={(event) => {
                  setLicense(event.target.value)
                }}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor={urlId}>Link (optional, https)</Label>
              <Input
                id={urlId}
                type="url"
                value={url}
                autoComplete="off"
                onChange={(event) => {
                  setUrl(event.target.value)
                }}
              />
            </div>
          </fieldset>
          <div className="space-y-2">
            <Label htmlFor={themeId}>Theme</Label>
            <NativeSelect
              id={themeId}
              value={theme}
              onChange={(event) => {
                setTheme(event.target.value)
              }}
            >
              <option value="">None (the default app theme)</option>
              {themes.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </NativeSelect>
          </div>
        </>
      )}
      {save.error && (
        <div role="alert" className="space-y-2 text-sm text-destructive">
          <p>{save.error.message}</p>
          {save.error instanceof StudioError && save.error.status === 409 && (
            <Button type="button" variant="outline" onClick={onReload}>
              Reload the latest version
            </Button>
          )}
        </div>
      )}
      {save.isSuccess && !edited && (
        <p role="status" className="text-sm text-muted-foreground">
          Saved.
        </p>
      )}
      <Button type="submit" disabled={!valid || save.isPending}>
        Save
      </Button>
    </form>
  )
}
