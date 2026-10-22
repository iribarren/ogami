import { BookOpen, ScrollText } from 'lucide-react'
import { useId, useState, type SubmitEvent } from 'react'

import { CAMPAIGN_THEME_NAMES } from '@/shared/theme/themes'
import { Alert, FieldError } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import { IconTile } from '@/shared/ui/icon-tile'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { SaveState, type SaveStateValue } from '@/shared/ui/save-state'
import { Textarea } from '@/shared/ui/textarea'

import {
  StudioError,
  useEditMetadata,
  type EditMetadataRequest,
  type GameSystem,
} from './useGameSystems'

/** The release contract's limit on a name, checked by the server too. */
const MAX_NAME_LENGTH = 100

/** The release contract's limit on the credits notice, checked by the server too. */
const MAX_NOTICE_LENGTH = 2000

/**
 * The server counts characters (code points), not UTF-16 units: an emoji is one. `.length`
 * would refuse a notice the server accepts.
 */
const characterCount = (text: string) => [...text].length

/** Empty text means "none" for the optional fields. */
const orNull = (text: string) => (text.trim() === '' ? null : text)

/**
 * The metadata of a draft: name, description and, from schema version 2 on, credits and theme
 * (schema version 1 and a library pack have neither, so they are hidden and not sent). Saving sends every field and
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
  // A library pack has neither credits nor a theme: the server refuses them.
  const hasCreditsAndTheme = gameSystem.schemaVersion >= 2 && gameSystem.kind !== 'library-pack'
  const [name, setName] = useState(gameSystem.name)
  const [description, setDescription] = useState(gameSystem.description ?? '')
  const [notice, setNotice] = useState(gameSystem.credits?.notice ?? '')
  const [license, setLicense] = useState(gameSystem.credits?.license ?? '')
  const [url, setUrl] = useState(gameSystem.credits?.url ?? '')
  const [theme, setTheme] = useState(gameSystem.theme ?? '')
  const save = useEditMetadata(gameSystem.key)
  // "Saved." describes the last save only: any later edit hides it.
  const [edited, setEdited] = useState(false)
  const [savedAt, setSavedAt] = useState<Date | null>(null)
  const nameId = useId()
  const descriptionId = useId()
  const noticeId = useId()
  const licenseId = useId()
  const urlId = useId()
  const themeId = useId()
  // Credits are all or nothing: a license or link without a notice has nothing to qualify.
  const creditsValid = notice.trim() !== '' || (license.trim() === '' && url.trim() === '')
  const noticeLength = characterCount(notice)
  const noticeTooLong = noticeLength > MAX_NOTICE_LENGTH
  const valid = name.trim() !== '' && (!hasCreditsAndTheme || (creditsValid && !noticeTooLong))
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
    save.mutate(body, {
      onSuccess: () => {
        setSavedAt(new Date())
      },
    })
  }

  const saveState: SaveStateValue | null = save.isPending
    ? { kind: 'saving' }
    : save.error
      ? { kind: 'failed', errors: 1 }
      : save.isSuccess && !edited && savedAt
        ? { kind: 'saved', at: savedAt }
        : edited
          ? { kind: 'unsaved' }
          : null
  const actionBar = (
    <>
      {save.error && (
        <div className="panel-body pt-0">
          <Alert
            action={
              save.error instanceof StudioError && save.error.status === 409 ? (
                <Button type="button" variant="outline" size="sm" onClick={onReload}>
                  Reload the latest version
                </Button>
              ) : undefined
            }
          >
            {save.error.message}
          </Alert>
        </div>
      )}
      <div className="action-bar">
        {saveState && <SaveState state={saveState} />}
        <Button type="submit" disabled={!valid || save.isPending}>
          Save
        </Button>
      </div>
    </>
  )

  return (
    <form
      aria-label="Metadata"
      className="space-y-6"
      onChange={() => {
        setEdited(true)
      }}
      onSubmit={handleSubmit}
    >
      <section aria-labelledby={`${nameId}-panel`} className="panel">
        <div className="panel-head">
          <IconTile size="sm">
            <BookOpen />
          </IconTile>
          <h2 id={`${nameId}-panel`} className="font-heading text-panel-title">
            Details
          </h2>
        </div>
        <div className="grid gap-4 panel-body sm:grid-cols-2">
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
          {hasCreditsAndTheme && (
            <div className="space-y-2">
              <Label htmlFor={themeId}>Theme</Label>
              <NativeSelect
                id={themeId}
                value={theme}
                aria-describedby={`${themeId}-hint`}
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
              <p id={`${themeId}-hint`} className="hint">
                Campaigns on this GameSystem start with it.
              </p>
            </div>
          )}
          <div className="space-y-2 sm:col-span-2">
            <Label htmlFor={descriptionId}>Description</Label>
            <Textarea
              id={descriptionId}
              value={description}
              onChange={(event) => {
                setDescription(event.target.value)
              }}
            />
          </div>
        </div>
        {!hasCreditsAndTheme && actionBar}
      </section>
      {hasCreditsAndTheme && (
        <section aria-labelledby={`${noticeId}-panel`} className="panel">
          <div className="panel-head">
            <IconTile size="sm">
              <ScrollText />
            </IconTile>
            <h2 id={`${noticeId}-panel`} className="font-heading text-panel-title">
              Credits
            </h2>
            <p className="ml-auto hint">Shown to players before they start a campaign.</p>
          </div>
          <div className="grid gap-4 panel-body sm:grid-cols-2">
            <div className="space-y-2 sm:col-span-2">
              <div className="flex items-baseline justify-between gap-3">
                <Label htmlFor={noticeId}>Notice</Label>
                <span id={`${noticeId}-count`} className="hint">
                  {noticeLength.toLocaleString('en-GB')} / {MAX_NOTICE_LENGTH.toLocaleString('en-GB')}
                </span>
              </div>
              <Textarea
                id={noticeId}
                value={notice}
                aria-invalid={!creditsValid || noticeTooLong}
                aria-describedby={`${noticeId}-count`}
                onChange={(event) => {
                  setNotice(event.target.value)
                }}
              />
              {noticeTooLong && (
                <FieldError>
                  The notice is limited to {MAX_NOTICE_LENGTH.toLocaleString('en-GB')} characters.
                </FieldError>
              )}
              {!creditsValid && (
                <FieldError>
                  A license or link needs a notice. Clear them, or write the notice.
                </FieldError>
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
          </div>
          {actionBar}
        </section>
      )}
    </form>
  )
}
