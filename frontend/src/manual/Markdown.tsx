import { Link } from '@tanstack/react-router'
import { type ComponentProps, createElement, type HTMLAttributes } from 'react'
import ReactMarkdown, { type Components } from 'react-markdown'
import remarkGfm from 'remark-gfm'

import { cn } from '@/shared/lib/utils'

/** A link on this site ("/play"), not a protocol-relative one ("//host"). */
const internal = (href: string) => href.startsWith('/') && !href.startsWith('//')
const external = (href: string) => /^https?:\/\//i.test(href)

const linkClass = 'underline underline-offset-4 hover:text-primary'

/**
 * Links: internal ones use the router, web links open in a new tab, and anything else (react-markdown
 * already blanks `javascript:` and similar) is shown as plain text.
 */
function ManualLink({ href, children }: ComponentProps<'a'> & { node?: unknown }) {
  if (href !== undefined && internal(href)) {
    return (
      <Link to={href} className={linkClass}>
        {children}
      </Link>
    )
  }
  if (href !== undefined && external(href)) {
    return (
      <a href={href} target="_blank" rel="noreferrer" className={linkClass}>
        {children}
      </a>
    )
  }
  return <>{children}</>
}

/** What react-markdown passes to an element override: the DOM props plus its own `node`. */
type Props = HTMLAttributes<HTMLElement> & { node?: unknown }

/** The props without `node` (react-markdown's syntax tree), which must not reach the DOM. */
function withoutNode(props: Props): HTMLAttributes<HTMLElement> {
  return Object.fromEntries(Object.entries(props).filter(([key]) => key !== 'node'))
}

/** An element override: the app's classes come first, so a class Markdown adds (`language-ts`) is kept. */
function styled(tag: string, classes: string) {
  return function Styled(props: Props) {
    const { className, ...rest } = withoutNode(props)
    return createElement(tag, { ...rest, className: cn(classes, className) })
  }
}

const components: Components = {
  h1: styled('h1', 'font-heading text-page-title break-words'),
  h2: styled('h2', 'mt-8 font-heading text-panel-title break-words'),
  h3: styled('h3', 'mt-6 font-heading text-lg font-semibold'),
  h4: styled('h4', 'mt-4 font-heading font-semibold'),
  p: styled('p', 'leading-7'),
  ul: styled('ul', 'list-disc space-y-1 pl-6'),
  ol: styled('ol', 'list-decimal space-y-1 pl-6'),
  blockquote: styled('blockquote', 'border-l-4 border-border pl-4 text-muted-foreground'),
  code: styled('code', 'rounded bg-muted px-1 py-0.5 font-mono text-sm'),
  pre: styled(
    'pre',
    'overflow-x-auto rounded-lg bg-muted p-3 font-mono text-sm [&_code]:bg-transparent [&_code]:p-0',
  ),
  hr: styled('hr', 'border-border'),
  a: ManualLink,
  table: (props: Props) => {
    const { className, ...rest } = withoutNode(props)
    return (
      <div className="overflow-x-auto">
        <table className={cn('w-full border-collapse text-sm', className)} {...rest} />
      </div>
    )
  },
  th: styled('th', 'border border-border px-3 py-2 text-left font-semibold'),
  td: styled('td', 'border border-border px-3 py-2 text-left'),
}

/**
 * Markdown as prose in the app's tokens. Raw HTML is not rendered: it shows as text, and no
 * rehype plugin turns it into elements.
 */
export function Markdown({ children }: { children: string }) {
  return (
    <div className="space-y-4">
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
        {children}
      </ReactMarkdown>
    </div>
  )
}
