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
 * The link of the latest email sent to `address`, as path plus fragment (for example
 * `/accept-invitation#token=…`). The link is built from APP_PUBLIC_URL (localhost:8080), which
 * the playwright container cannot reach, so only the part after the origin is returned and the
 * test opens it on its own baseURL. Polls for up to 10 seconds: mail is sent right after the
 * request commits, but the SMTP hop to Mailpit is not instant.
 */
export async function latestLinkTo(address: string): Promise<string> {
  const query = encodeURIComponent(`to:"${address}"`)
  for (let attempt = 0; attempt < 20; attempt++) {
    // Newest first.
    const { messages } = await getJson<{ messages: Summary[] }>(
      `/api/v1/search?query=${query}&limit=1`,
    )
    const latest = messages.at(0)
    if (latest !== undefined) {
      const { Text } = await getJson<Message>(`/api/v1/message/${latest.ID}`)
      const link = /https?:\/\/[^\s/]+(\/[\w-]+#token=[\w-]+)/.exec(Text)
      if (link?.[1] === undefined) {
        throw new Error(`No account link in the email to ${address}`)
      }
      return link[1]
    }
    await new Promise((resolve) => setTimeout(resolve, 500))
  }
  throw new Error(`No email to ${address} reached Mailpit`)
}
