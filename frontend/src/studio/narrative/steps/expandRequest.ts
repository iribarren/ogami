/**
 * The last press of an "Expand all" (open) or "Collapse all" button; `press` counts them, 0 for none
 * yet. A collapsible row follows the newest press once, then is the author's again.
 */
export interface ExpandRequest {
  open: boolean
  press: number
}
