import { createFileRoute } from '@tanstack/react-router'

import { StudioHomePage } from '@/studio/StudioHomePage'

export const Route = createFileRoute('/studio')({ component: StudioHomePage })
