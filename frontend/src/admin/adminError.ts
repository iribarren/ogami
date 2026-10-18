/** A refused or failed Admin call; `message` is ready to show the owner. */
export class AdminError extends Error {
  override name = 'AdminError'

  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

interface Context {
  /** What is gone on a 404. */
  subject: 'user' | 'invitation'
  /** The sentence that ends "failed with HTTP …": "Disabling the user". */
  failure: string
  /** What a 503 means for this call, when it is a mail failure. */
  mailFailure?: string
}

/** The 409s of Identity, told apart by the API message (the error body has no code). */
const conflicts: [RegExp, string][] = [
  [/your own account/, 'Your own account is protected.'],
  [/last active owner/, 'The last active owner cannot be disabled or lose the OWNER role.'],
  [/already exists/, 'A user with this email already exists.'],
  [/pending invitation/, 'This email already has a pending invitation.'],
  [/is disabled/, 'This user is disabled. Enable the account first.'],
]

/**
 * Turns an Admin API refusal into a message: 404 says the user or invitation is gone, 409 is
 * mapped from the API message, 422 shows the API's field message, 503 is a mail failure, and
 * anything else is generic. An unrecognised 409 shows the API message rather than hiding it.
 */
export function adminError(
  response: Response,
  error: { error: string } | undefined,
  { subject, failure, mailFailure }: Context,
): AdminError {
  const reason = error?.error ?? ''
  const { status } = response
  const generic = `${failure} failed with HTTP ${String(status)}.`
  const message = (() => {
    switch (status) {
      case 404:
        return `This ${subject} no longer exists.`
      case 409:
        return conflicts.find(([pattern]) => pattern.test(reason))?.[1] ?? (reason || generic)
      case 422:
        return reason || generic
      case 503:
        return mailFailure ?? generic
      default:
        return generic
    }
  })()
  return new AdminError(message, status)
}
