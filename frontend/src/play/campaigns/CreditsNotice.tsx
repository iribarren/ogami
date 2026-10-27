import { creditsBadges } from '@/shared/credits/badges'

import type { GameSystemSummary } from './useCampaigns'

// Looked up by any string: the API may name a badge this build has no logo for.
const knownBadges: Partial<Record<string, (typeof creditsBadges)['dark-pack']>> = creditsBadges

/**
 * The credits of a release (ADR 0021): its notice, such as a fan preset's publisher disclaimer,
 * with the license as a link when the release gives its URL, and the logo of its badge (ADR 0044)
 * beside them. Nothing for a release without credits.
 */
export function CreditsNotice({ credits }: { credits: GameSystemSummary['credits'] }) {
  if (credits === null) {
    return null
  }
  // A blank license name never makes an empty link: the URL names itself.
  const name = credits.license?.trim() ? credits.license : null
  const license = name ?? credits.url
  // A badge this build does not know (the API is newer than the SPA) shows the notice without a logo.
  const badge = credits.badge === null ? null : (knownBadges[credits.badge] ?? null)
  return (
    <section
      aria-label="About this game"
      className="flex max-w-prose items-start gap-3 rounded-lg border bg-muted/40 p-3 text-sm"
    >
      <div className="min-w-0 flex-1 space-y-1">
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
      </div>
      {badge !== null && (
        // The logo is a black disc with transparent lettering: a white backing keeps it readable in dark mode.
        <img
          src={badge.src}
          alt={badge.alt}
          width={48}
          height={48}
          className="size-12 shrink-0 rounded-full bg-white"
        />
      )}
    </section>
  )
}
