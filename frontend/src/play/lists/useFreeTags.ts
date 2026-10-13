import { useState } from 'react'

/**
 * The state of the free tags field: a list, never one string, because a tag may hold a comma.
 * Unsaved text in the input is kept as one more tag on save, so typing and saving loses nothing.
 */
export function useFreeTags(initial: string[]) {
  const [freeTags, setFreeTags] = useState(initial)
  const [draft, setDraft] = useState('')

  function add() {
    const tag = draft.trim()
    if (tag !== '') {
      setFreeTags([...freeTags, tag])
      setDraft('')
    }
  }

  return {
    freeTags,
    draft,
    setDraft,
    add,
    remove: (index: number) => {
      setFreeTags(freeTags.filter((_, held) => held !== index))
    },
    /** The tags to save: the list, plus the text typed and not yet added. */
    toSave: () => (draft.trim() === '' ? freeTags : [...freeTags, draft.trim()]),
  }
}
