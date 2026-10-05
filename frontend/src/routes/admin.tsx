import { createFileRoute } from '@tanstack/react-router'

import { AdminHomePage } from '@/admin/AdminHomePage'

export const Route = createFileRoute('/admin')({ component: AdminHomePage })
