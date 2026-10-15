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
