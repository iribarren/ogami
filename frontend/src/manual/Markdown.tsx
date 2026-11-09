import { Link } from '@tanstack/react-router'
import type { ComponentProps } from 'react'
import ReactMarkdown, { type Components } from 'react-markdown'
import remarkGfm from 'remark-gfm'

/** A link on this site ("/play"), not a protocol-relative one ("//host"). */
const internal = (href: string) => href.startsWith('/') && !href.startsWith('//')
const external = (href: string) => /^https?:\/\//i.test(href)

const linkClass = 'underline underline-offset-4 hover:text-primary'

/**
 * Links: internal ones use the router, web links open in a new tab, and anything else (react-markdown
 * already blanks `javascript:` and similar) is shown as plain text.
 */
function ManualLink({ href, children }: ComponentProps<'a'>) {
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

const components: Components = {
  h1: (props) => <h1 className="font-heading text-page-title break-words" {...props} />,
  h2: (props) => <h2 className="mt-8 font-heading text-panel-title break-words" {...props} />,
  h3: (props) => <h3 className="mt-6 font-heading text-lg font-semibold" {...props} />,
  h4: (props) => <h4 className="mt-4 font-heading font-semibold" {...props} />,
  p: (props) => <p className="leading-7" {...props} />,
  ul: (props) => <ul className="list-disc space-y-1 pl-6" {...props} />,
  ol: (props) => <ol className="list-decimal space-y-1 pl-6" {...props} />,
  blockquote: (props) => (
    <blockquote className="border-l-4 border-border pl-4 text-muted-foreground" {...props} />
  ),
  code: (props) => <code className="rounded bg-muted px-1 py-0.5 font-mono text-sm" {...props} />,
  pre: (props) => (
    <pre
      className="overflow-x-auto rounded-lg bg-muted p-3 font-mono text-sm [&_code]:bg-transparent [&_code]:p-0"
      {...props}
    />
  ),
  hr: () => <hr className="border-border" />,
  a: ManualLink,
  table: (props) => (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-sm" {...props} />
    </div>
  ),
  th: (props) => (
    <th className="border border-border px-3 py-2 text-left font-semibold" {...props} />
  ),
  td: (props) => <td className="border border-border px-3 py-2 text-left" {...props} />,
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
