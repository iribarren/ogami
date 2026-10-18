import { useEffect, useState } from 'react'

function readToken(): string | null {
  const token = new URLSearchParams(window.location.hash.slice(1)).get('token')
  return token !== null && token.trim() !== '' ? token : null
}

/**
 * The token of an emailed link (`#token=…`), read once when the page mounts. The fragment is
 * then dropped from the address bar so the token is not left in the history entry, a copied
 * URL or a screenshot; it never goes into a query string. `null` for a missing or blank token.
 */
export function useFragmentToken(): string | null {
  const [token] = useState(readToken)

  useEffect(() => {
    if (window.location.hash !== '') {
      window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname + window.location.search,
      )
    }
  }, [])

  return token
}
