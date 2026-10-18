const mailpit = process.env.MAILPIT_URL ?? 'http://mailpit:8025'

interface Summary {
  ID: string
}

interface Message {
  Text: string
}

async function getJson<T>(path: string): Promise<T> {
  const response = await fetch(`${mailpit}${path}`)
  if (!response.ok) {
    throw new Error(`Mailpit answered HTTP ${String(response.status)} for ${path}`)
  }
  return (await response.json()) as T
}

/**
 * The newest link in an email to `address` whose path is `path` (`/accept-invitation` or
 * `/reset-password`), as path plus fragment (for example `/accept-invitation#token=…`). The
 * link is built from APP_PUBLIC_URL (localhost:8080), which the playwright container cannot
 * reach, so only the part after the origin is returned and the test opens it on its own
 * baseURL. Asking for the path keeps a scenario from reading another kind of email to the same
 * address. Polls for up to 10 seconds: the SMTP hop to Mailpit is not instant.
 */
export async function latestLinkTo(address: string, path: string): Promise<string> {
  const query = encodeURIComponent(`to:"${address}"`)
  const link = new RegExp(`https?://[^\\s/]+(${path}#token=[\\w-]+)`)
  for (let attempt = 0; attempt < 20; attempt++) {
    // Newest first.
    const { messages } = await getJson<{ messages: Summary[] }>(
      `/api/v1/search?query=${query}&limit=10`,
    )
    for (const { ID } of messages) {
      const { Text } = await getJson<Message>(`/api/v1/message/${ID}`)
      const found = link.exec(Text)?.[1]
      if (found !== undefined) {
        return found
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 500))
  }
  throw new Error(`No ${path} link in an email to ${address} reached Mailpit`)
}
