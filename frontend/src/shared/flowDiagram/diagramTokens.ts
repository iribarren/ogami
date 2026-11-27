/**
 * Every theme token the drawing paints with. The print stylesheet must define each one, because it
 * does not load the app's theme; a test reads this list against it.
 */
export const DIAGRAM_TOKENS = [
  'background',
  'foreground',
  'card',
  'border',
  'muted',
  'accent',
  'muted-foreground',
  'primary',
  'info',
] as const

export type DiagramToken = (typeof DIAGRAM_TOKENS)[number]
