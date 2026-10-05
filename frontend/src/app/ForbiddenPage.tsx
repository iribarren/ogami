import { PagePlaceholder } from '@/shared/ui/PagePlaceholder'

/** Shown in place of an area the signed-in user holds no role for. */
export function ForbiddenPage({ area }: { area: string }) {
  return <PagePlaceholder title="Access denied" summary={`You don't have access to ${area}.`} />
}
