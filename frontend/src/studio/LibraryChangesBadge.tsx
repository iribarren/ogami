import { Badge } from '@/shared/ui/badge'

/** Warns that library items a draft imported changed or went away in their pack; nothing at 0. */
export function LibraryChangesBadge({ count }: { count: number }) {
  if (count === 0) {
    return null
  }
  return (
    <Badge variant="warning">
      {count === 1 ? '1 library change' : `${String(count)} library changes`}
    </Badge>
  )
}
