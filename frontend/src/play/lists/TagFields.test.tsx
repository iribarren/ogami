import { render, screen } from '@testing-library/react'

import { type ReleaseTag, TagFields } from './TagFields'

const free = { freeTags: [], draft: '', setDraft: () => undefined, add: () => undefined } as never

const releaseTags: ReleaseTag[] = [
  { key: 'villain', label: 'Villain', hint: null, appliesTo: ['npc'] },
  { key: 'hero', label: 'Hero', hint: null, appliesTo: ['character'] },
  { key: 'site', label: 'Site', hint: null, appliesTo: ['place'] },
]

describe('TagFields', () => {
  it('marks a held Tag that no longer applies to the kind as no longer offered', () => {
    render(
      <TagFields
        id="t"
        kind="character"
        releaseTags={releaseTags}
        held={['villain']}
        free={free}
      />,
    )

    expect(screen.getByRole('checkbox', { name: /Villain/ })).toBeChecked()
    expect(screen.getByText('(no longer offered)')).toBeInTheDocument()
  })

  it('does not mark an offered Tag', () => {
    render(
      <TagFields id="t" kind="character" releaseTags={releaseTags} held={['hero']} free={free} />,
    )

    expect(screen.getByRole('checkbox', { name: /Hero/ })).toBeChecked()
    expect(screen.queryByText('(no longer offered)')).not.toBeInTheDocument()
  })

  it('offers the Tags that apply to Places and the ones for every kind, not the others', () => {
    render(
      <TagFields
        id="t"
        kind="place"
        releaseTags={[...releaseTags, { key: 'any', label: 'Any', hint: null, appliesTo: null }]}
        held={[]}
        free={free}
      />,
    )

    expect(screen.getAllByRole('checkbox').map((box) => box.getAttribute('value'))).toEqual([
      'site',
      'any',
    ])
  })
})
