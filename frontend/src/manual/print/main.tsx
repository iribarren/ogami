import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import { PrintApp } from './PrintApp'
import './print.css'

const root = document.getElementById('root')
if (!root) {
  throw new Error('Missing #root element in print.html')
}

createRoot(root).render(
  <StrictMode>
    <PrintApp doc={new URLSearchParams(window.location.search).get('doc')} />
  </StrictMode>,
)
