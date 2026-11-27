import { File as NodeFile } from 'node:buffer'

import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterAll, beforeAll, vi } from 'vitest'

import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import { CardImagesSection } from './CardImagesSection'
import { cardBackImage, cardFrontImage } from './fixtures'

const LIST = 'GET /api/studio/game-systems/my-draft/card-images'
const BACK = 'PUT /api/studio/game-systems/my-draft/card-images/cardBack'
const REMOVE_BACK = 'DELETE /api/studio/game-systems/my-draft/card-images/cardBack'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

function renderSection(handlers: Record<string, FakeHandler>) {
  return renderWithApi(<CardImagesSection gameSystemKey="my-draft" />, handlers)
}

// jsdom's FormData and File are not the ones Node's Request and Response understand, so the
// upload would fail before reaching the fake API: the test uses Node's pair, as a browser's own.
const png = () => new NodeFile(['png'], 'back.png', { type: 'image/png' }) as unknown as File

beforeAll(async () => {
  const sample = await new Response(new URLSearchParams()).formData()
  vi.stubGlobal('FormData', sample.constructor)
})

afterAll(() => {
  vi.unstubAllGlobals()
})

describe('the card images section', () => {
  it('states the image rules', async () => {
    renderSection({ [LIST]: json([]) })

    const section = await screen.findByRole('region', { name: 'Card images' })
    expect(within(section).getByText(/PNG or JPEG, at most 5 MB/)).toBeInTheDocument()
    expect(within(section).getByText(/815 × 1110 px/)).toBeInTheDocument()
    expect(within(section).getByText(/408 × 555 px up to 1630 × 2220 px/)).toBeInTheDocument()
    expect(within(section).getByText(/69:94, give or take 2 %/)).toBeInTheDocument()
    expect(within(section).getByText(/5 mm safe area/)).toBeInTheDocument()
  })

  it('shows an empty slot with an upload action and a filled one with its preview', async () => {
    renderSection({ [LIST]: json([cardFrontImage]) })

    const back = await screen.findByRole('group', { name: 'Card back' })
    expect(within(back).getByText('No image: the cards print plain.')).toBeInTheDocument()
    expect(within(back).getByRole('button', { name: 'Upload card back' })).toBeInTheDocument()
    expect(within(back).queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument()
    const front = screen.getByRole('group', { name: 'Card frame' })
    expect(within(front).getByAltText('Card frame preview')).toHaveAttribute(
      'src',
      cardFrontImage.url,
    )
    expect(within(front).getByText('408 × 555 px, JPEG')).toBeInTheDocument()
    expect(within(front).getByRole('button', { name: 'Replace card frame' })).toBeInTheDocument()
  })

  it('uploads a file as multipart and shows the new image', async () => {
    let stored = false
    let received = ''
    renderSection({
      [LIST]: () => Response.json(stored ? [cardBackImage] : []),
      [BACK]: async (request) => {
        // Not request.formData(): jsdom's File breaks Node's multipart parser.
        expect(request.headers.get('content-type')).toMatch(/^multipart\/form-data; boundary=/)
        received = await request.text()
        stored = true
        return Response.json(cardBackImage)
      },
    })

    const back = await screen.findByRole('group', { name: 'Card back' })
    await userEvent.upload(within(back).getByLabelText('Upload card back file'), png())

    expect(await within(back).findByAltText('Card back preview')).toHaveAttribute(
      'src',
      cardBackImage.url,
    )
    expect(received).toContain('name="image"; filename="back.png"')
  })

  it.each([
    ['unsupported_format', 'Only PNG and JPEG images are accepted.'],
    ['too_large', 'The image is larger than 5 MB.'],
    ['too_few_pixels', 'at least 408 × 555 px'],
    ['too_many_pixels', 'at most 1630 × 2220 px'],
    ['wrong_aspect_ratio', '69:94, give or take 2 %'],
    ['unreadable', 'not a readable PNG or JPEG image'],
  ])('explains the refusal %s', async (reason, message) => {
    renderSection({
      [LIST]: json([]),
      [BACK]: json({ error: 'raw server text', reason }, 422),
    })

    const back = await screen.findByRole('group', { name: 'Card back' })
    await userEvent.upload(within(back).getByLabelText('Upload card back file'), png())

    const alert = await within(back).findByRole('alert')
    expect(alert).toHaveTextContent(message)
    expect(alert).not.toHaveTextContent('raw server text')
  })

  it('falls back to the API message for a refusal this build does not know', async () => {
    renderSection({
      [LIST]: json([]),
      [BACK]: json({ error: 'The image has a new problem.', reason: 'new_reason' }, 422),
    })

    const back = await screen.findByRole('group', { name: 'Card back' })
    await userEvent.upload(within(back).getByLabelText('Upload card back file'), png())

    expect(await within(back).findByRole('alert')).toHaveTextContent('The image has a new problem.')
  })

  it('says a 413 is the upload limit, and any other failure generically', async () => {
    let status = 413
    renderSection({
      [LIST]: json([]),
      [BACK]: () => Response.json({ error: 'boom' }, { status }),
    })

    const back = await screen.findByRole('group', { name: 'Card back' })
    await userEvent.upload(within(back).getByLabelText('Upload card back file'), png())
    expect(await within(back).findByRole('alert')).toHaveTextContent(/limit is 5 MB/)

    status = 500
    await userEvent.upload(within(back).getByLabelText('Upload card back file'), png())
    await waitFor(() => {
      expect(within(back).getByRole('alert')).toHaveTextContent(
        'Uploading the image failed with HTTP 500.',
      )
    })
  })

  it('asks before removing, then empties the slot', async () => {
    let removed = false
    const { fakeApi } = renderSection({
      [LIST]: () => Response.json(removed ? [] : [cardBackImage]),
      [REMOVE_BACK]: () => {
        removed = true
        return new Response(null, { status: 204 })
      },
    })

    const back = await screen.findByRole('group', { name: 'Card back' })
    await userEvent.click(within(back).getByRole('button', { name: 'Remove card back' }))
    expect(fakeApi.requests).not.toContain(REMOVE_BACK)
    await userEvent.click(await screen.findByRole('button', { name: 'Remove the card back' }))

    expect(await within(back).findByText('No image: the cards print plain.')).toBeInTheDocument()
    expect(fakeApi.requests).toContain(REMOVE_BACK)
  })

  it('keeps the confirmation open and shows why when the removal fails', async () => {
    renderSection({
      [LIST]: json([cardBackImage]),
      [REMOVE_BACK]: json({ error: 'No such GameSystem.' }, 404),
    })

    const back = await screen.findByRole('group', { name: 'Card back' })
    await userEvent.click(within(back).getByRole('button', { name: 'Remove card back' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Remove the card back' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('No such GameSystem.')
  })

  it('shows why the list could not load', async () => {
    renderSection({ [LIST]: json({ error: 'Not found.' }, 404) })

    expect(await screen.findByRole('alert')).toHaveTextContent('Not found.')
  })
})
