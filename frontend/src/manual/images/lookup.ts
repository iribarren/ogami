// The image files dropped next to this module, by path. Eager, so the app and the print build both
// find them; an empty folder gives an empty map, and the page shows a placeholder instead.
const files = import.meta.glob<string>('./*.{webp,png,jpg}', {
  eager: true,
  query: '?url',
  import: 'default',
})

/** The URL of an illustration file in this folder, or undefined while the file does not exist. */
export function imageUrl(file: string): string | undefined {
  return files[`./${file}`]
}
