/**
 * Whether a scene's title says more than its Scene Type already does. The title the API gives a
 * scene started from a Scene Type alone is the type's name, numbered after the scenes of that
 * type ("Tour 2"); showing both would repeat the name. Compared ignoring case.
 */
export function titleAddsToType(title: string, typeName: string | null): boolean {
  if (typeName === null || typeName.trim() === '') {
    return true
  }
  const name = typeName.trim().toLowerCase()
  const held = title.trim().toLowerCase()
  const repeated =
    held === name || (held.startsWith(`${name} `) && /^\d+$/.test(held.slice(name.length + 1)))
  return !repeated
}

/**
 * The scene's type label in a heading ("Scene 2 · Tour"): none when the Scene Type is unknown or
 * its name is the word "Scene", which "Scene 2" already says.
 */
export function typeLabel(typeName: string | null): string | null {
  const name = typeName?.trim() ?? ''
  return name === '' || name.toLowerCase() === 'scene' ? null : name
}

/**
 * The number a scene of play shows in a session: only scenes of play are counted, in the order
 * they started (1, 2, 3 without gaps), never a hook Scene (a world turn takes a stored number
 * but no ordinal). Stored scene numbers, which every command uses, stay unchanged.
 *
 * @returns the ordinal by stored scene number; a hook Scene has none
 */
export function playOrdinals(
  scenes: readonly { number: number; kind: 'scene' | 'hook' }[],
): Map<number, number> {
  const ordinals = new Map<number, number>()
  for (const { number } of [...scenes]
    .filter(({ kind }) => kind === 'scene')
    .sort((a, b) => a.number - b.number)) {
    ordinals.set(number, ordinals.size + 1)
  }
  return ordinals
}
