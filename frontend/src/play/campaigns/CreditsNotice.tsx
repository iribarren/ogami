import type { GameSystemSummary } from './useCampaigns'

/**
 * The credits of a release (ADR 0021): its notice, such as a fan preset's publisher disclaimer,
 * with the license as a link when the release gives its URL. Nothing for a release without
 * credits.
 */
export function CreditsNotice({ credits }: { credits: GameSystemSummary['credits'] }) {
  if (credits === null) {
    return null
  }
  const license = credits.license ?? credits.url
  return (
    <section
      aria-label="About this game"
      className="max-w-prose space-y-1 rounded-lg border bg-muted/40 p-3 text-sm"
    >
      <p className="font-medium">About this game</p>
      <p className="whitespace-pre-line text-muted-foreground">{credits.notice}</p>
      {license !== null && (
        <p className="text-muted-foreground">
          License:{' '}
          {credits.url === null ? (
            license
          ) : (
            <a
              href={credits.url}
              target="_blank"
              rel="noreferrer noopener"
              className="underline underline-offset-4 hover:text-foreground"
            >
              {license}
            </a>
          )}
        </p>
      )}
    </section>
  )
}
