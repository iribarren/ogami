/** The outcome of an action that has no dialog: a success (`status`) or a failure (`alert`). */
export type NoticeState = { kind: 'success' | 'error'; text: string } | null

export function Notice({ notice }: { notice: NoticeState }) {
  if (notice === null) {
    return null
  }
  return notice.kind === 'success' ? (
    <p role="status" className="text-sm text-success-foreground">
      {notice.text}
    </p>
  ) : (
    <p role="alert" className="text-sm text-destructive">
      {notice.text}
    </p>
  )
}
