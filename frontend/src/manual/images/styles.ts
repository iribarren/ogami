/** The art style of a manual illustration: its key, its display name and its prompt preamble. */
export interface ImageStyle {
  key: string
  name: string
  preamble: string
}

/**
 * The art styles of the manual. Each preamble is copied verbatim from the `## Prompt preamble` of
 * `docs/art/<key>.md`, and the two change together, by hand: the node container mounts only
 * `frontend/`, so no test can compare them. A new style is one guide and one entry here.
 */
export const imageStyles: readonly ImageStyle[] = [
  {
    key: 'house',
    name: 'House',
    preamble:
      'Pen-and-ink line illustration with a flat, muted watercolour wash on warm off-white paper. A quiet tabletop still life in soft daylight with generous empty space. Restrained palette of ochre, slate blue and faded red that still reads clearly in grayscale. No text, letters, numbers or symbols anywhere; card faces and pages are blank or illegible. No faces; when people appear, only their hands.',
  },
  {
    key: 'hard-country',
    name: 'Hard Country',
    preamble:
      'Ink drawing with the weight of a woodcut and a cold, thin wash. Pre-modern hard country in late autumn: frost, mud, bare trees, a low grey sky, shortening light. Palette of peat brown, frost grey and tallow-yellow lamplight that reads clearly in grayscale. Plain village-made things: rope, wooden poles, wool, leather. A weary, devout, unheroic mood. Figures are small and seen from behind or at a distance, never their faces. A carried body is always a shrouded shape on a litter. No church iconography, crosses or haloes, nothing modern, no text, letters or symbols.',
  },
  {
    key: 'dogwatch',
    name: 'Dogwatch',
    preamble:
      'Clean technical ink line with a cold, desaturated wash. The interior of a working cargo hauler in deep space, grounded near-future hardware: bolted panels, cable runs, frosted metal, worn paint, utility lights kept at half. Palette of gunmetal, sodium amber and pale cryo blue that reads clearly in grayscale. Quiet, too orderly, watchful. No people in frame, or only gloved hands; no faces. No creature, alien, monster or blood, no readable screens or text, no logos, no glowing red eyes, no weapons.',
  },
]

/** The keys of `imageStyles`, sorted, for an error message. */
export const imageStyleKeys: string[] = imageStyles.map((style) => style.key).sort()

/** The prompt to give the image tool: the style's preamble, a space and the subject. */
export function imagePrompt(style: string, subject: string): string {
  const found = imageStyles.find((entry) => entry.key === style)
  if (found === undefined) {
    throw new Error(
      `Unknown image style "${style}", expected one of: ${imageStyleKeys.join(', ')}.`,
    )
  }
  return `${found.preamble} ${subject}`
}
