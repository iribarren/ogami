import { createFileRoute } from '@tanstack/react-router'

import { LandingPage } from '@/app/LandingPage'

export const Route = createFileRoute('/')({ component: LandingPage })
